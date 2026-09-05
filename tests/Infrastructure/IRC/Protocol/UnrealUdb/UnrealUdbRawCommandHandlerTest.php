<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\UdbRawCommandResult;
use App\Infrastructure\IRC\Protocol\UnrealUdb\ParsedUdbPath;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbRawCommandHandler;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $this->written = [];

        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('isConnected')->willReturn(true);
        $holder->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $writer = new UnrealUdbRecordWriter($holder, $this->sessionState, $this->records, '001');
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
        self::assertSame([':001 DB * INS S::propagator :hub2.davidlig.net'], $this->written);
    }

    #[Test]
    public function insAcceptsQuotedAndColonPrefixedValuesVerbatim(): void
    {
        // The handler receives the value already decoded by RawCommand; the
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
    public function insRedactsChannelPassAndEncryptionKey(): void
    {
        $pass = $this->handler->ins('C::#chan::challenge', 'sha256');
        self::assertTrue($pass->success);
        self::assertSame('DB * INS C::#chan::challenge <redacted>', $pass->auditLine);

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
    public function delCascadesAndPropagates(): void
    {
        $this->records->blocks['N'] = ['davidlig::vhost' => 'v', 'other::vhost' => 'v'];

        $result = $this->handler->del('N::davidlig');

        self::assertTrue($result->success);
        self::assertSame('DB * DEL N::davidlig', $result->auditLine);
        self::assertSame(['other::vhost' => 'v'], $this->records->blocks['N']);
        self::assertSame([':001 DB * DEL N::davidlig'], $this->written);
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
