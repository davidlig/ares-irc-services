<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Infrastructure\OperServ\Subscriber\OperServGlineEnforceSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stringable;

#[CoversClass(OperServGlineEnforceSubscriber::class)]
final class OperServGlineEnforceSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsNetworkSyncComplete(): void
    {
        $events = OperServGlineEnforceSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NetworkSyncCompleteEvent::class, $events);
    }

    #[Test]
    public function onSyncCompleteAppliesActiveGlines(): void
    {
        $gline1 = Gline::create('*@badhost1.com', null, 'Spam');
        $gline2 = Gline::create('*@badhost2.com', null, 'Bots');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findActive')->willReturn([$gline1, $gline2]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::exactly(2))->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteWithNoGlinesDoesNothing(): void
    {
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findActive')->willReturn([]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteNoProtocolModuleLogsWarning(): void
    {
        $gline = Gline::create('*@test1234.com', null, 'Test');
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findActive')->willReturn([$gline]);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $warningLogged = false;
        $logger = new class($warningLogged) extends NullLogger {
            private bool $warningLogged;

            public function __construct(bool &$warningLogged)
            {
                $this->warningLogged = &$warningLogged;
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->warningLogged = true;
            }
        };

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            $logger,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        $subscriber->onSyncComplete($event);

        self::assertTrue($warningLogged);
    }

    #[Test]
    public function onSyncCompleteNoServerSidLogsWarning(): void
    {
        $gline = Gline::create('*@test1234.com', null, 'Test');
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findActive')->willReturn([$gline]);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn(null);

        $warningLogged = false;
        $logger = new class($warningLogged) extends NullLogger {
            private bool $warningLogged;

            public function __construct(bool &$warningLogged)
            {
                $this->warningLogged = &$warningLogged;
            }

            public function warning(string|Stringable $message, array $context = []): void
            {
                $this->warningLogged = true;
            }
        };

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            $logger,
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        $subscriber->onSyncComplete($event);

        self::assertTrue($warningLogged);
    }

    #[Test]
    public function onSyncCompleteUsesCorrectDuration(): void
    {
        $futureExpiry = new DateTimeImmutable('+1 hour');
        $gline = Gline::create('*@test1234.com', null, 'Test', $futureExpiry);

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findActive')->willReturn([$gline]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline')
            ->with(
                '001',
                '*',
                'test1234.com',
                self::greaterThan(3500),
                'Test',
            );

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompletePermanentGlineUsesZeroDuration(): void
    {
        $gline = Gline::create('*@test1234.com', null, 'Test');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findActive')->willReturn([$gline]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline')
            ->with('001', '*', 'test1234.com', 0, 'Test');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteParsesUserAtHostMaskCorrectly(): void
    {
        $gline = Gline::create('ident@*.badisp.com', null, 'Test');

        $glineRepo = $this->createStub(GlineRepositoryInterface::class);
        $glineRepo->method('findActive')->willReturn([$gline]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline')
            ->with('001', 'ident', '*.badisp.com', 0, 'Test');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkSyncCompleteEvent($connection, '001');

        $subscriber->onSyncComplete($event);
    }
}
