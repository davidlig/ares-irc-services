<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbConnectionLifecycleSubscriber;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionController;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionCoordinator;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbConnectionLifecycleSubscriber::class)]
final class UdbConnectionLifecycleSubscriberTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    #[Test]
    public function connectionLostResetsTheCoordinatorSoTheNextLinkStartsOver(): void
    {
        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
        );
        $subscriber = new UdbConnectionLifecycleSubscriber($coordinator);

        $coordinator->setOwnName('services.example.net');
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $coordinator->onLinkReady($this->createConnection());

        // First link announced HEL once; a lost connection must clear the
        // volatile state so the replacement link re-announces it.
        $subscriber->onConnectionLost(new ConnectionLostEvent($this->createServerLink(), 'connection reset'));

        $this->written = [];
        // The new link re-captures both identities before HEL.
        $coordinator->setOwnName('services.example.net');
        $coordinator->onRemoteServer('001', 'ircd.example.net');
        $coordinator->onLinkReady($this->createConnection());

        self::assertMatchesRegularExpression('/^:002 DB 001 HEL 4 services\.example\.net [0-9a-f]{16} OCL OCLG$/', $this->firstWrittenLine());
    }

    #[Test]
    public function subscribedEventsIncludeConnectionLost(): void
    {
        $events = UdbConnectionLifecycleSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ConnectionLostEvent::class, $events);
    }

    #[Test]
    public function connectionLostAlsoClearsTheProtocolHandlersCapturedPeerIdentity(): void
    {
        $remoteIdentities = [];
        $coordinator = $this->createStub(UdbSessionController::class);
        $coordinator->method('onRemoteServer')->willReturnCallback(static function (string $sid, string $name) use (&$remoteIdentities): void {
            $remoteIdentities[] = [$sid, $name];
        });
        $handler = new UnrealUdbProtocolHandler('002', $coordinator);
        $connection = $this->createConnection();
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['SID=001']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['old.example.net', '1']), $connection);

        new UdbConnectionLifecycleSubscriber($coordinator, protocolHandler: $handler)
            ->onConnectionLost(new ConnectionLostEvent($this->createServerLink(), 'connection reset'));

        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['new.example.net', '1']), $connection);
        self::assertSame([['001', 'old.example.net']], $remoteIdentities);
    }

    private function createConnection(): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        return $connection;
    }

    private function firstWrittenLine(): string
    {
        foreach ($this->written as $line) {
            return $line;
        }

        self::fail('Expected at least one written line.');
    }

    private function createServerLink(): ServerLink
    {
        return new ServerLink(
            serverName: new ServerName('services.test.local'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7000),
            password: new LinkPassword('secret'),
            description: 'Ares IRC Services',
        );
    }
}
