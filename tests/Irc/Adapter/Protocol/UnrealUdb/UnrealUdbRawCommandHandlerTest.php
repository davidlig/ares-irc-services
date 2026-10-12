<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\ParsedUdbPath;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRawCommandHandler;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(UnrealUdbRawCommandHandler::class)]
#[CoversClass(UdbRawCommandResult::class)]
#[CoversClass(ParsedUdbPath::class)]
final class UnrealUdbRawCommandHandlerTest extends TestCase
{
    private FakeUdbRecords $records;

    private RecordingSessionState $sessionState;

    /** @var list<string> */
    private array $written = [];

    private UnrealUdbRawCommandHandler $handler;

    protected function setUp(): void
    {
        $this->records = new FakeUdbRecords();
        $this->sessionState = new RecordingSessionState(true);
        $this->records->beginLocalStream($this->sessionState->streamOrigin());
        $this->written = [];

        $writer = new UnrealUdbRecordWriter($this->sessionState, $this->records);
        $this->handler = new UnrealUdbRawCommandHandler($writer);
    }

    #[Test]
    public function insValidatesPersistsAndPropagates(): void
    {
        $result = $this->handler->ins('S::propagator', 'hub2.davidlig.net');

        self::assertTrue($result->success);
        self::assertNull($result->errorKey);
        self::assertSame('DB * INS S::propagator hub2.davidlig.net', $result->auditLine);
        self::assertSame(['propagator' => 'hub2.davidlig.net'], $this->records->blocks['S']);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insAcceptsQuotedAndColonPrefixedValuesVerbatim(): void
    {
        // The handler receives the value already decoded by the protocol interceptor; the
        // raw form is stored as-is (quoting is stripped there).
        $result = $this->handler->ins('N::davidlig::vhost', 'cloaked.host');

        self::assertTrue($result->success);
        self::assertSame(['davidlig::vhost' => 'cloaked.host'], $this->records->blocks['N']);
    }

    #[Test]
    public function insRejectsUnknownBlocks(): void
    {
        $result = $this->handler->ins('X::path', 'value');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_block', $result->errorKey);
        self::assertSame(['%block%' => 'X'], $result->errorParams);
        self::assertSame([], $this->records->blocks);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insRejectsLowercaseBlockLetters(): void
    {
        $result = $this->handler->ins('n::davidlig::vhost', 'v');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_block', $result->errorKey);
    }

    #[Test]
    public function insRejectsNonCanonicalPaths(): void
    {
        $result = $this->handler->ins('N::david%4Aig::vhost', 'v');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_path', $result->errorKey);
        self::assertSame(['%path%' => 'N::david%4Aig::vhost'], $result->errorParams);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insRejectsPathsBeyondTheBlockPrefix(): void
    {
        $result = $this->handler->ins('N', 'value');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_path', $result->errorKey);
    }

    #[Test]
    public function insRejectsValuesFailingTheSchema(): void
    {
        $result = $this->handler->ins('N::davidlig::unknown', 'value');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_value', $result->errorKey);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insRejectsOversizedValues(): void
    {
        $result = $this->handler->ins('N::davidlig::swhois', str_repeat('x', 5000));

        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_value', $result->errorKey);
    }

    #[Test]
    public function insRedactsSecretValuesFromAuditAndLogs(): void
    {
        $result = $this->handler->ins('N::davidlig::pass', 'sha256:' . str_repeat('a', 64));

        self::assertTrue($result->success);
        self::assertSame('DB * INS N::davidlig::pass <redacted>', $result->auditLine);
        self::assertSame(['sha256:' . str_repeat('a', 64)], [$this->records->blocks['N']['davidlig::pass']]);
    }

    #[Test]
    #[DataProvider('channelModesProvider')]
    public function insRedactsChannelModesInLogsAndAuditButPreservesMutation(string $key, string $value): void
    {
        $path = 'C::#chan::' . $key;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('UDB RAW INS applied by operator.', [
            'path' => $path,
            'value' => '<redacted>',
        ]);
        $handler = new UnrealUdbRawCommandHandler(new UnrealUdbRecordWriter($this->sessionState, $this->records), $logger);

        $result = $handler->ins($path, $value);

        self::assertTrue($result->success);
        self::assertSame('DB * INS ' . $path . ' <redacted>', $result->auditLine);
        self::assertSame($value, $this->records->blocks['C']['#chan::' . $key]);
        self::assertCount(1, $this->sessionState->queue);
        self::assertSame($value, $this->sessionState->queue[0]->value?->value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function channelModesProvider(): iterable
    {
        foreach (['modes', 'MoDeS'] as $key) {
            yield $key . ' with channel key' => [$key, '+ntk synthetic-channel-key'];
            yield $key . ' without channel key' => [$key, '+nt'];
        }
    }

    #[Test]
    public function insRedactsRejectedChannelModesValues(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');
        $handler = new UnrealUdbRawCommandHandler(new UnrealUdbRecordWriter($this->sessionState, $this->records), $logger);

        foreach (['modes', 'MoDeS'] as $key) {
            foreach (['', '+nt synthetic-extra-parameter', "+k synthetic-channel-key\n"] as $value) {
                $path = 'C::#chan::' . $key;
                $result = $handler->ins($path, $value);

                self::assertFalse($result->success);
                self::assertSame('raw.udb.invalid_value', $result->errorKey);
                self::assertSame(['%path%' => $path . ' <redacted>'], $result->errorParams);
                self::assertNull($result->auditLine);
            }
        }
        self::assertSame([], $this->records->blocks);
        self::assertSame([], $this->sessionState->queue);
    }

    #[Test]
    public function storeFailuresRedactChannelModesForInsertAndDelete(): void
    {
        $this->records->fail = true;
        foreach (['modes', 'MoDeS'] as $key) {
            $path = 'C::#chan::' . $key;
            foreach ([$this->handler->ins($path, '+k synthetic-channel-key'), $this->handler->del($path)] as $result) {
                self::assertFalse($result->success);
                self::assertSame('raw.udb.error', $result->errorKey);
                self::assertSame(['%path%' => $path . ' <redacted>'], $result->errorParams);
                self::assertNull($result->auditLine);
            }
        }
        self::assertSame([], $this->sessionState->queue);
    }

    #[Test]
    public function insKeepsNonSecretTopicVisibleInLogsAndAudit(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('UDB RAW INS applied by operator.', [
            'path' => 'C::#chan::topic',
            'value' => 'Visible topic',
        ]);
        $handler = new UnrealUdbRawCommandHandler(new UnrealUdbRecordWriter($this->sessionState, $this->records), $logger);

        $result = $handler->ins('C::#chan::topic', 'Visible topic');

        self::assertTrue($result->success);
        self::assertSame('DB * INS C::#chan::topic Visible topic', $result->auditLine);
    }

    #[Test]
    public function insRejectsRemovedChannelSecretsAndRedactsEncryptionKey(): void
    {
        $pass = $this->handler->ins('C::#chan::challenge', 'sha256');
        self::assertFalse($pass->success);
        self::assertSame('raw.udb.invalid_value', $pass->errorKey);

        $key = $this->handler->ins('S::encryption_key', str_repeat('a', 64));
        self::assertTrue($key->success);
        self::assertSame('DB * INS S::encryption_key <redacted>', $key->auditLine);
    }

    #[Test]
    public function insReportsStoreFailuresWithoutPropagating(): void
    {
        $this->records->fail = true;

        $result = $this->handler->ins('S::propagator', 'hub2.davidlig.net');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.error', $result->errorKey);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function rawRejectsForbiddenChannelModesButRetainsKeyAndLimitSemantics(): void
    {
        foreach (['+r', '-P', '+ntO', '+q nick', '-v nick'] as $value) {
            $result = $this->handler->ins('C::#chan::modes', $value);
            self::assertFalse($result->success);
            self::assertSame('raw.udb.invalid_value', $result->errorKey);
            self::assertSame(['%path%' => 'C::#chan::modes <redacted>'], $result->errorParams);
        }
        foreach (['+k synthetic-key', '-k synthetic-key', '+l 10', '-l', '+beI a b c', '-beI a b c'] as $value) {
            self::assertTrue($this->handler->ins('C::#chan::modes', $value)->success);
        }
    }

    #[Test]
    public function rawRejectsLinePrefixInsertionButSubtreeDeletionStillCascades(): void
    {
        $this->records->blocks['K'] = ['G::*@host::reason' => 'synthetic reason', 'G::*@host::expires' => '*1', 'G::*@other::reason' => 'retained'];
        $result = $this->handler->ins('K::G::*@host', 'synthetic reason');
        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_value', $result->errorKey);
        self::assertTrue($this->handler->del('K::G::*@host')->success);
        self::assertSame(['G::*@other::reason' => 'retained'], UdbTestValues::logicalMap($this->records->recordsByBlock('K')));
    }

    #[Test]
    public function delCascadesAndPropagates(): void
    {
        $this->records->blocks['N'] = ['davidlig::vhost' => 'v', 'other::vhost' => 'v'];

        $result = $this->handler->del('N::davidlig');

        self::assertTrue($result->success);
        self::assertSame('DB * DEL N::davidlig', $result->auditLine);
        self::assertSame(['other::vhost' => 'v'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        self::assertSame([], $this->written);
    }

    #[Test]
    public function delRejectsInvalidPaths(): void
    {
        $result = $this->handler->del('N::davidlig::');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.invalid_path', $result->errorKey);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function delRedactsSecretPathsInErrors(): void
    {
        $this->records->fail = true;

        $result = $this->handler->del('N::davidlig::pass');

        self::assertFalse($result->success);
        self::assertSame('raw.udb.error', $result->errorKey);
        self::assertSame(['%path%' => 'N::davidlig::pass <redacted>'], $result->errorParams);
    }

    #[Test]
    public function resultsUseTheSharedSuccessAndErrorFactories(): void
    {
        $success = UdbRawCommandResult::success('audit');
        self::assertTrue($success->success);
        self::assertSame('audit', $success->auditLine);
        self::assertNull($success->errorKey);
        self::assertSame([], $success->errorParams);

        $error = UdbRawCommandResult::error('raw.udb.invalid_path', ['%path%' => 'p']);
        self::assertFalse($error->success);
        self::assertSame('raw.udb.invalid_path', $error->errorKey);
        self::assertSame(['%path%' => 'p'], $error->errorParams);
        self::assertNull($error->auditLine);
    }
}
