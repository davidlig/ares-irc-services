<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Connection;

use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Domain\Event\ConnectionLostEvent;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveConnectionHolder::class)]
final class ActiveConnectionHolderTest extends TestCase
{
    private ActiveConnectionHolder $holder;

    protected function setUp(): void
    {
        $this->holder = new ActiveConnectionHolder();
    }

    #[Test]
    public function getSubscribedEventsReturnsBurstCompleteAndConnectionLost(): void
    {
        self::assertSame(
            [
                NetworkBurstCompleteEvent::class => ['onBurstComplete', 250],
                ConnectionLostEvent::class => ['onConnectionLost', 0],
            ],
            ActiveConnectionHolder::getSubscribedEvents(),
        );
    }

    #[Test]
    public function beforeBurstConnectionAndServerSidAreNull(): void
    {
        self::assertNull($this->holder->getConnection());
        self::assertNull($this->holder->getServerSid());
        self::assertFalse($this->holder->isConnected());
    }

    #[Test]
    public function onBurstCompleteSetsConnectionAndServerSid(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $this->holder->onBurstComplete($event);

        self::assertSame($connection, $this->holder->getConnection());
        self::assertSame('001', $this->holder->getServerSid());
        self::assertTrue($this->holder->isConnected());
    }

    #[Test]
    public function isConnectedReturnsFalseWhenConnectionIsNotConnected(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(false);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $this->holder->onBurstComplete($event);

        self::assertFalse($this->holder->isConnected());
    }

    #[Test]
    public function onConnectionLostClearsConnectionAndServerSid(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $this->holder->onBurstComplete($event);
        $this->holder->setRemoteServerSid('002');
        self::assertTrue($this->holder->isConnected());

        $this->holder->onConnectionLost(new ConnectionLostEvent(
            new ServerLink(
                new ServerName('srv.local'),
                new Hostname('127.0.0.1'),
                new Port(6667),
                new LinkPassword('pwd'),
                'desc',
            ),
            'remote closed',
        ));

        self::assertNull($this->holder->getConnection());
        self::assertNull($this->holder->getServerSid());
        self::assertFalse($this->holder->isConnected());
    }

    #[Test]
    public function getProtocolModuleReturnsNullByDefault(): void
    {
        self::assertNull($this->holder->getProtocolModule());
    }

    #[Test]
    public function setProtocolModuleAndGetProtocolModule(): void
    {
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $this->holder->setProtocolModule($module);
        self::assertSame($module, $this->holder->getProtocolModule());
    }

    #[Test]
    public function getProtocolHandlerReturnsNullWhenNoModule(): void
    {
        self::assertNull($this->holder->getProtocolHandler());
    }

    #[Test]
    public function getProtocolHandlerDelegatesToModuleWhenSet(): void
    {
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->holder->setProtocolModule($module);
        self::assertSame($handler, $this->holder->getProtocolHandler());
    }

    #[Test]
    public function writeLineDoesNothingWhenNotConnected(): void
    {
        $this->holder->writeLine('PING');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function writeLineDelegatesToConnectionWhenConnected(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with('PING 123');
        $this->holder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->holder->writeLine('PING 123');
    }

    #[Test]
    public function setRemoteServerSidStoresValue(): void
    {
        $this->holder->setRemoteServerSid('994');
        self::assertSame('994', $this->holder->getRemoteServerSid());
    }
}
