<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Connection;

use App\Application\Port\ProtocolModuleInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
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
    public function getSubscribedEvents_returns_burst_complete(): void
    {
        self::assertSame(
            [NetworkBurstCompleteEvent::class => ['onBurstComplete', 0]],
            ActiveConnectionHolder::getSubscribedEvents(),
        );
    }

    #[Test]
    public function beforeBurst_connectionAndServerSidAreNull(): void
    {
        self::assertNull($this->holder->getConnection());
        self::assertNull($this->holder->getServerSid());
        self::assertFalse($this->holder->isConnected());
    }

    #[Test]
    public function onBurstComplete_setsConnectionAndServerSid(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $this->holder->onBurstComplete($event);

        self::assertSame($connection, $this->holder->getConnection());
        self::assertSame('001', $this->holder->getServerSid());
        self::assertTrue($this->holder->isConnected());
    }

    #[Test]
    public function getProtocolModule_returnsNullByDefault(): void
    {
        self::assertNull($this->holder->getProtocolModule());
    }

    #[Test]
    public function setProtocolModule_andGetProtocolModule(): void
    {
        $module = $this->createMock(ProtocolModuleInterface::class);
        $this->holder->setProtocolModule($module);
        self::assertSame($module, $this->holder->getProtocolModule());
    }

    #[Test]
    public function getProtocolHandler_returnsNullWhenNoModule(): void
    {
        self::assertNull($this->holder->getProtocolHandler());
    }

    #[Test]
    public function getProtocolHandler_delegatesToModuleWhenSet(): void
    {
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $module = $this->createMock(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->holder->setProtocolModule($module);
        self::assertSame($handler, $this->holder->getProtocolHandler());
    }

    #[Test]
    public function writeLine_doesNothingWhenNotConnected(): void
    {
        $this->holder->writeLine('PING');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function writeLine_delegatesToConnectionWhenConnected(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with('PING 123');
        $this->holder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->holder->writeLine('PING 123');
    }
}
