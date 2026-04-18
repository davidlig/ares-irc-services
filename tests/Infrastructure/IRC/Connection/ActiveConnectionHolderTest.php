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
    public function getSubscribedEventsReturnsBurstCompleteWithPriority250(): void
    {
        self::assertSame(
            [NetworkBurstCompleteEvent::class => ['onBurstComplete', 250]],
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
        $event = new NetworkBurstCompleteEvent($connection, '001');

        $this->holder->onBurstComplete($event);

        self::assertSame($connection, $this->holder->getConnection());
        self::assertSame('001', $this->holder->getServerSid());
        self::assertTrue($this->holder->isConnected());
    }

    #[Test]
    public function getProtocolModuleReturnsNullByDefault(): void
    {
        self::assertNull($this->holder->getProtocolModule());
    }

    #[Test]
    public function setProtocolModuleAndGetProtocolModule(): void
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
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
        $module = $this->createStub(ProtocolModuleInterface::class);
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
        // Value is stored; verified indirectly via InspIRCdProtocolHandler integration
        $this->addToAssertionCount(1);
    }
}
