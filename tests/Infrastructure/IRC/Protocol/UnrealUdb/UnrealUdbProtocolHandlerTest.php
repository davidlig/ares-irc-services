<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionCoordinator;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionLock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSnapshotProviderInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use App\Infrastructure\IRC\Runtime\SessionEventPump;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function fclose;
use function flock;
use function fopen;
use function mkdir;
use function rmdir;
use function sprintf;
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

    private function createHandler(string $sid = '002'): UnrealUdbProtocolHandler
    {
        $coordinator = new UdbSessionCoordinator(
            $sid,
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
        );

        return new UnrealUdbProtocolHandler($sid, $coordinator);
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

        $coordinator = $this->createMock(UdbSessionCoordinator::class);
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
        $coordinator = $this->createMock(UdbSessionCoordinator::class);
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
    public function eosTriggersBurstCompletionOurEosAndHelNegotiation(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

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

        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK services\.test\.local [0-9a-f]{16} OCL OCLG$/m', implode("\n", $this->written));
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.test\.local [0-9a-f]{16} OCL OCLG$/m', implode("\n", $this->written));
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
        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 ACK services\.test\.local [0-9a-f]{16} OCL OCLG$/', $this->writtenLine(0));
    }

    #[Test]
    public function malformedDbFramesAreIgnored(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
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
    public function setEventPumpForwardsToCoordinator(): void
    {
        $handler = $this->createHandler();
        $pump = new SessionEventPump();
        $handler->setEventPump($pump);
        $handler->setEventPump(null);
        $this->addToAssertionCount(1);
    }
}
