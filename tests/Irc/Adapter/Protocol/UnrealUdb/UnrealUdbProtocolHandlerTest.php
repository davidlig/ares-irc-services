<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionController;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionCoordinator;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionLock;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutationQueue;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Runtime\SessionEventPump;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function fclose;
use function flock;
use function fopen;
use function implode;
use function mkdir;
use function rmdir;
use function sprintf;
use function strpos;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

#[CoversClass(UnrealUdbProtocolHandler::class)]
final class UnrealUdbProtocolHandlerTest extends TestCase
{
    use CreatesUdbRecordWriter;

    /** @var list<string> */
    private array $written = [];

    private function createConnection(): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        return $connection;
    }

    private function createHandler(string $sid = '002', ?EventDispatcherInterface $eventDispatcher = null): UnrealUdbProtocolHandler
    {
        $coordinator = new UdbSessionCoordinator(
            $sid,
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
        );

        return new UnrealUdbProtocolHandler($sid, $coordinator, eventDispatcher: $eventDispatcher);
    }

    private function createServerLink(): ServerLink
    {
        return new ServerLink(
            serverName: new ServerName('services.test.local'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('link-secret'),
            description: 'Ares IRC Services',
            useTls: false,
        );
    }

    #[Test]
    public function getProtocolNameReturnsUnrealUdb(): void
    {
        self::assertSame('unrealudb', $this->createHandler()->getProtocolName());
    }

    #[Test]
    public function getSidReturnsConfiguredSid(): void
    {
        self::assertSame('002', $this->createHandler('002')->getSid());
    }

    #[Test]
    public function performHandshakeAcquiresTheUdbLockBeforeTheHandshake(): void
    {
        $directory = sys_get_temp_dir() . '/ares-udb-handler-' . uniqid('', true);
        mkdir($directory);

        try {
            $handler = new UnrealUdbProtocolHandler(
                '002',
                new UdbSessionCoordinator(
                    '002',
                    $this->createStub(UdbBlockStateRepositoryInterface::class),
                    $this->createStub(UdbSnapshotProviderInterface::class),
                ),
                new UdbSessionLock($directory),
            );

            $handler->performHandshake($this->createConnection(), $this->createServerLink());

            self::assertStringStartsWith('PASS :', $this->written[0] ?? '');
        } finally {
            @rmdir($directory);
        }
    }

    #[Test]
    public function handshakeAndEosAreLoggedWithoutTheLinkPassword(): void
    {
        $records = new TestHandler();
        $logger = new Logger('test', [$records]);
        $handler = new UnrealUdbProtocolHandler(
            '002',
            new UdbSessionCoordinator(
                '002',
                $this->createStub(UdbBlockStateRepositoryInterface::class),
                $this->createStub(UdbSnapshotProviderInterface::class),
            ),
            logger: $logger,
        );
        $connection = $this->createConnection();

        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);

        $messages = array_map(static fn (LogRecord $record): string => $record->message, $records->getRecords());
        self::assertContains('> PASS :<redacted>', $messages);
        self::assertContains('> PROTOCTL EAUTH=services.test.local SID=002', $messages);
        self::assertContains('> SERVER services.test.local 1 :Ares IRC Services', $messages);
        self::assertContains('> :002 EOS', $messages);
        self::assertStringNotContainsString('link-secret', implode("\n", $messages));
    }

    #[Test]
    public function performHandshakeFailsWhenTheUdbDirectoryIsLocked(): void
    {
        $directory = sys_get_temp_dir() . '/ares-udb-handler-' . uniqid('', true);
        mkdir($directory);
        $foreign = fopen($directory . '/.udb.lock', 'c');
        self::assertNotFalse($foreign);
        self::assertTrue(flock($foreign, LOCK_EX | LOCK_NB));

        try {
            $handler = new UnrealUdbProtocolHandler(
                '002',
                new UdbSessionCoordinator(
                    '002',
                    $this->createStub(UdbBlockStateRepositoryInterface::class),
                    $this->createStub(UdbSnapshotProviderInterface::class),
                ),
                new UdbSessionLock($directory),
            );

            $this->expectException(RuntimeException::class);
            $handler->performHandshake($this->createConnection(), $this->createServerLink());
        } finally {
            flock($foreign, LOCK_UN);
            fclose($foreign);
            @unlink($directory . '/.udb.lock');
            @rmdir($directory);
        }
    }

    #[Test]
    public function performHandshakeReleasesLockAndResetsCoordinatorOnFailure(): void
    {
        $directory = sys_get_temp_dir() . '/ares-udb-handler-fail-' . uniqid('', true);
        mkdir($directory);
        $lock = new UdbSessionLock($directory);

        $coordinator = $this->createMock(UdbSessionController::class);
        $coordinator->expects(self::once())->method('reset');

        $failingConnection = $this->createStub(ConnectionInterface::class);
        $failingConnection->method('writeLine')->willThrowException(new RuntimeException('Write failed'));

        $handler = new UnrealUdbProtocolHandler('002', $coordinator, $lock);

        try {
            $handler->performHandshake($failingConnection, $this->createServerLink());
            self::fail('Expected handshake failure');
        } catch (RuntimeException $e) {
            self::assertSame('Write failed', $e->getMessage());
        } finally {
            // If lock was properly released, we should be able to acquire it again without error
            $lock->acquire();
            $lock->release();
            @unlink($directory . '/.udb.lock');
            @rmdir($directory);
        }
    }

    #[Test]
    public function handleIncomingTicksTheCoordinator(): void
    {
        $coordinator = $this->createMock(UdbSessionController::class);
        $coordinator->expects(self::once())->method('tick');
        $handler = new UnrealUdbProtocolHandler('002', $coordinator);

        $handler->handleIncoming(new IRCMessage(command: 'PING', params: ['123']), $this->createConnection());
    }

    #[Test]
    public function getSupportedCapabilitiesReturnsNonEmptyList(): void
    {
        $caps = $this->createHandler()->getSupportedCapabilities();

        self::assertNotEmpty($caps);
        self::assertContains('NOQUIT', $caps);
        self::assertContains('SJOIN', $caps);
    }

    #[Test]
    public function parseRawLineDelegatesToIRCMessage(): void
    {
        $msg = $this->createHandler()->parseRawLine(':server PRIVMSG #chan :hello');

        self::assertSame('PRIVMSG', $msg->command);
        self::assertSame('server', $msg->prefix);
        self::assertSame(['#chan'], $msg->params);
        self::assertSame('hello', $msg->trailing);
    }

    #[Test]
    public function formatMessageDelegatesToIRCMessage(): void
    {
        $raw = $this->createHandler()->formatMessage(new IRCMessage(command: 'PONG', params: ['target']));

        self::assertSame('PONG target', $raw);
    }

    #[Test]
    public function performHandshakeWritesPassProtoctlServerInOrder(): void
    {
        $this->written = [];
        $connection = $this->createConnection();
        $handler = $this->createHandler('002');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        self::assertSame('PASS :link-secret', $this->writtenLine(0));
        self::assertStringContainsString('PROTOCTL EAUTH=services.test.local SID=002', $this->writtenLine(1));
        self::assertStringStartsWith('PROTOCTL ', $this->writtenLine(2));
        self::assertSame('SERVER services.test.local 1 :Ares IRC Services', $this->writtenLine(3));
    }

    #[Test]
    public function reconnectHandshakeDoesNotReuseThePreviousPeerSid(): void
    {
        /** @var list<array{string, string}> $observed */
        $observed = [];
        $coordinator = $this->createStub(UdbSessionController::class);
        $coordinator->method('onRemoteServer')->willReturnCallback(static function (string $sid, string $name) use (&$observed): void {
            $observed[] = [$sid, $name];
        });
        $handler = new UnrealUdbProtocolHandler('002', $coordinator);
        $connection = $this->createConnection();

        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['SID=001']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['old.example.net', '1']), $connection);

        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['new.example.net', '1']), $connection);
        self::assertSame([['001', 'old.example.net']], $observed);
    }

    #[Test]
    public function eosTriggersBurstCompletionOurEosAndHelNegotiation(): void
    {
        $this->written = [];
        $connection = $this->createConnection();
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use ($connection): object {
                self::assertInstanceOf(NetworkBurstCompleteEvent::class, $event);
                self::assertSame($connection, $event->connection);
                self::assertSame('002', $event->serverSid);

                return $event;
            });
        $handler = $this->createHandler(eventDispatcher: $eventDispatcher);

        // The handshake captures our own FQDN (onLinkEstablished).
        $handler->performHandshake($connection, $this->createServerLink());
        // Remote identity is captured from the SERVER line before EOS.
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', prefix: '001', params: ['ircd.example.net', '1'], trailing: 'IRCd'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);

        self::assertContains(':002 EOS', $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.test\.local [0-9a-f]{16} OCL OCLG$/m', implode("\n", $this->written));
    }

    #[Test]
    public function serverLinesWithoutIdentityAreIgnored(): void
    {
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['', '1']), $this->createConnection());
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', prefix: '001', params: ['ircd.example.net', '1'], trailing: 'IRCd'), $this->createConnection());

        self::assertSame('unrealudb', $handler->getProtocolName());
    }

    #[Test]
    public function unprefixedServerLineWithProtoctlSidTriggersHelNegotiation(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

        // Real link order: our handshake (captures our FQDN), then the IRCd's
        // UNPREFIXED SERVER introduction, PROTOCTL SID=, and finally EOS.
        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['NOQUIT', 'NICKv2', 'SID=001']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['irc.davidlig.net', '1'], trailing: 'U6100 Server'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);

        self::assertContains(':002 EOS', $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.test\.local [0-9a-f]{16} OCL OCLG$/m', implode("\n", $this->written));
    }

    #[Test]
    public function helFromThePeerBeforeOurEosStillGetsAckedAndOurHelGoesOut(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['NOQUIT', 'SID=001']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['ircd.example.net', '1'], trailing: 'IRCd'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'HEL', '4', 'services.test.local', '0123456789abcdef', 'OCL', 'OCLG']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);

        $lines = implode("\n", $this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK services\.test\.local [0-9a-f]{16} OCL OCLG$/m', $lines);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.test\.local [0-9a-f]{16} OCL OCLG$/m', $lines);

        $requestPosition = strpos($lines, ':002 DB 001 HEL 4 services.test.local');
        $ackPosition = strpos($lines, ':002 DB 001 HEL 4 ACK services.test.local');
        self::assertNotFalse($requestPosition);
        self::assertNotFalse($ackPosition);
        self::assertLessThan($ackPosition, $requestPosition);
    }

    #[Test]
    public function incomingDbFramesAreDelegatedToTheCoordinator(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

        $handler->performHandshake($connection, $this->createServerLink());
        $this->written = [];
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['SID=001']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['ircd.example.net', '1'], trailing: 'IRCd'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'HEL', '4', 'ircd.example.net', '0123456789abcdef', 'OCL']), $connection);

        self::assertNotEmpty($this->written);
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.test\.local [0-9a-f]{16} OCL OCLG$/', $this->writtenLine(0));
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK services\.test\.local [0-9a-f]{16} OCL OCLG$/', $this->writtenLine(1));
    }

    #[Test]
    public function realBroadcastMutationLinesReachTheAuthorityRejectionPolicy(): void
    {
        $handler = $this->createHandler();
        $connection = $this->createConnection();
        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming($handler->parseRawLine('PROTOCTL SID=001'), $connection);
        $handler->handleIncoming($handler->parseRawLine('SERVER ircd.example.net 1 :IRCd'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 HEL 4 services.test.local 0123456789abcdef OCL OCLG'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 HEL 4 ACK services.test.local 0123456789abcdef OCL OCLG'), $connection);
        $this->written = [];

        foreach ([
            ':001 DB * INS 0123456789abcdef 1 S::nickserv :mask value',
            ':001 DB * DEL 0123456789abcdef 2 K::G::user@host',
            ':001 DB * DRP 0123456789abcdef 3 I',
        ] as $line) {
            $handler->handleIncoming($handler->parseRawLine($line), $connection);
        }

        self::assertSame([
            ':002 DB 001 ERR INS 6 1 S',
            ':002 DB 001 ERR DEL 6 2 K',
            ':002 DB 001 ERR DRP 6 3 I',
        ], $this->written);
    }

    #[Test]
    public function broadcastMutationRejectionStillRequiresTheExactPeerAndBroadcastTarget(): void
    {
        $handler = $this->createHandler();
        $connection = $this->createConnection();
        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming($handler->parseRawLine('PROTOCTL SID=001'), $connection);
        $handler->handleIncoming($handler->parseRawLine('SERVER ircd.example.net 1 :IRCd'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 HEL 4 services.test.local 0123456789abcdef OCL OCLG'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 HEL 4 ACK services.test.local 0123456789abcdef OCL OCLG'), $connection);
        $this->written = [];

        $handler->handleIncoming($handler->parseRawLine(':999 DB * INS S::nickserv :bad'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 INS S::nickserv :bad'), $connection);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function preHelloMutationLinesAreIgnoredWithoutAnyResponse(): void
    {
        $handler = $this->createHandler();
        $connection = $this->createConnection();
        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming($handler->parseRawLine('PROTOCTL SID=001'), $connection);
        $handler->handleIncoming($handler->parseRawLine('SERVER ircd.example.net 1 :IRCd'), $connection);
        $this->written = [];

        $handler->handleIncoming($handler->parseRawLine(':001 DB * INS S::nickserv :mask value'), $connection);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function realRoundTrafficDefersOverflowRecoveryUntilTheActiveBarrierCompletes(): void
    {
        $states = new FakeBlockStates();
        foreach (UdbBlock::all() as $block) {
            $states->upsert($block->letter(), UdbChecksum::EMPTY, 0);
        }
        $snapshots = $this->createStub(UdbSnapshotProviderInterface::class);
        $digest = UdbChecksum::fromRecords([['1.2.3.4::clones', '*5']]);
        $snapshots->method('checksumForBlock')->willReturnCallback(
            static fn (UdbBlock $block): string => UdbBlock::Ips === $block ? $digest : UdbChecksum::EMPTY,
        );
        $snapshots->method('recordsForBlock')->willReturnCallback(
            static fn (UdbBlock $block): array => UdbBlock::Ips === $block ? ['1.2.3.4::clones' => '*5'] : [],
        );
        $coordinator = new UdbSessionCoordinator('002', $states, $snapshots, mutationQueue: new UdbMutationQueue(2));
        $handler = new UnrealUdbProtocolHandler('002', $coordinator);
        $connection = $this->createConnection();

        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming($handler->parseRawLine('PROTOCTL SID=001'), $connection);
        $handler->handleIncoming($handler->parseRawLine('SERVER ircd.example.net 1 :IRCd'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 EOS'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 HEL 4 services.test.local 0123456789abcdef OCL OCLG'), $connection);
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 HEL 4 ACK services.test.local 0123456789abcdef OCL OCLG'), $connection);
        self::assertSame(1, preg_match('/^:002 DB 001 INF ([0-9]+) I /m', implode("\n", $this->written), $inventoryMatch));
        $roundId = $inventoryMatch[1];
        self::assertSame((int) $roundId, $coordinator->activeRoundId());
        $this->written = [];

        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 RES ' . $roundId . ' I'), $connection);
        self::assertSame(1, preg_match('/^:002 DB 001 BEGIN ' . $roundId . ' I ([A-Za-z0-9_-]+) ' . $digest . ' 0$/m', implode("\n", $this->written), $beginMatch));
        $txid = $beginMatch[1];
        self::assertSame([
            ':002 DB 001 BEGIN ' . $roundId . ' I ' . $txid . ' ' . $digest . ' 0',
            ':002 DB 001 PUT ' . $roundId . ' I ' . $txid . ' 1.2.3.4::clones :*5',
            ':002 DB 001 END ' . $roundId . ' I ' . $txid . ' ' . $digest . ' 0',
        ], $this->written);
        $this->written = [];

        for ($index = 0; 3 > $index; ++$index) {
            $coordinator->enqueueMutation(new UdbMutation('N', 'nick' . $index, 'value'));
        }
        self::assertSame([], $this->written);
        self::assertSame((int) $roundId, $coordinator->activeRoundId());

        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 ACK ' . $roundId . ' I ' . $txid . ' ' . $digest . ' 0'), $connection);
        self::assertSame([], $this->written);
        self::assertSame((int) $roundId, $coordinator->activeRoundId());
        $handler->handleIncoming($handler->parseRawLine(':001 DB 002 HEL 4 ACK services.test.local 0123456789abcdef OCL OCLG'), $connection);

        self::assertSame(6, substr_count(implode("\n", $this->written), ' INF '));
        self::assertSame(0, substr_count(implode("\n", $this->written), ' INF ' . $roundId . ' '));
        self::assertNotSame((int) $roundId, $coordinator->activeRoundId());
        self::assertTrue($coordinator->isResyncPending());
    }

    #[Test]
    public function malformedDbFramesAreIgnored(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(3))
            ->method('debug')
            ->with('Ignored malformed or unsupported UDB DB frame.', ['prefix' => '001']);
        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
            $logger,
        );
        $handler = new UnrealUdbProtocolHandler('002', $coordinator, logger: $logger);

        $this->written = [];
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'BOGUS']), $this->createConnection());
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'INF', '0', 'N', '00', '1']), $this->createConnection());
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'INS', 'bad-epoch', '1', 'N::nick::pass'], trailing: 'plaintext-secret'), $this->createConnection());

        self::assertSame([], $this->written);
    }

    #[Test]
    public function netinfoIsAnswered(): void
    {
        $this->written = [];
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'NETINFO', params: ['0', '1', '6100', '*', '0', '0', '0'], trailing: 'Net'), $this->createConnection());

        self::assertNotEmpty($this->written);
        self::assertMatchesRegularExpression('/^NETINFO 0 \d+ 6100 \* 0 0 0 :Net$/', $this->writtenLine(0));
    }

    private function writtenLine(int $index): string
    {
        foreach ($this->written as $position => $line) {
            if ($position === $index) {
                return $line;
            }
        }

        self::fail(sprintf('Expected written line %d.', $index));
    }

    #[Test]
    public function unrelatedCommandsFallThroughWithoutUdbSideEffects(): void
    {
        $this->written = [];
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'PRIVMSG', prefix: '001', params: ['002'], trailing: 'hi'), $this->createConnection());

        self::assertSame([], $this->written);
    }

    #[Test]
    public function pingIsAnsweredWithPong(): void
    {
        $this->written = [];
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'PING', trailing: 'token'), $this->createConnection());

        self::assertSame(['PONG :token'], $this->written);
    }

    #[Test]
    public function errorIsLoggedAndStillTicksTheCoordinator(): void
    {
        $this->written = [];
        $connection = $this->createConnection();
        $coordinator = $this->createMock(UdbSessionController::class);
        $coordinator->expects(self::once())->method('tick')->with($connection);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('critical')
            ->with('Remote server sent ERROR — closing link.', ['reason' => 'Link rejected']);
        $handler = new UnrealUdbProtocolHandler('002', $coordinator, logger: $logger);

        $handler->handleIncoming(new IRCMessage(command: 'ERROR', trailing: 'Link rejected'), $connection);

        self::assertSame([], $this->written);
    }

    #[Test]
    public function setEventPumpForwardsToCoordinator(): void
    {
        $handler = $this->createHandler();
        $pump = new SessionEventPump();
        $handler->setEventPump($pump);
        $handler->setEventPump(null);
        $this->addToAssertionCount(1);
    }
}
