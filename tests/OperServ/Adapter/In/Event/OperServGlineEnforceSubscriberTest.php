<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\OperServ\Adapter\In\Event\OperServGlineEnforceSubscriber;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
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

        self::assertArrayHasKey(NetworkSynchronizationCompletedEvent::class, $events);
    }

    #[Test]
    public function onSyncCompleteAppliesActiveGlines(): void
    {
        $gline1 = new GlineEntry('*@badhost1.com', null, 'Spam', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, 1);
        $gline2 = new GlineEntry('*@badhost2.com', null, 'Bots', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, 2);

        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findActiveAt')->willReturn([$gline1, $gline2]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::exactly(2))->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteWithNoGlinesDoesNothing(): void
    {
        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findActiveAt')->willReturn([]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('addGline');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteNoProtocolModuleLogsWarning(): void
    {
        $gline = new GlineEntry('*@test1234.com', null, 'Test', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, 1);
        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findActiveAt')->willReturn([$gline]);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $logger = new class extends NullLogger {
            public bool $warningLogged = false;

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

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber->onSyncComplete($event);

        self::assertTrue($logger->warningLogged);
    }

    #[Test]
    public function onSyncCompleteNoServerSidLogsWarning(): void
    {
        $gline = new GlineEntry('*@test1234.com', null, 'Test', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, 1);
        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findActiveAt')->willReturn([$gline]);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn(null);

        $logger = new class extends NullLogger {
            public bool $warningLogged = false;

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

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber->onSyncComplete($event);

        self::assertTrue($logger->warningLogged);
    }

    #[Test]
    public function onSyncCompleteUsesCorrectDuration(): void
    {
        $futureExpiry = new DateTimeImmutable('+1 hour');
        $gline = new GlineEntry('*@test1234.com', null, 'Test', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $futureExpiry, 1);

        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findActiveAt')->willReturn([$gline]);

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

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompletePermanentGlineUsesZeroDuration(): void
    {
        $gline = new GlineEntry('*@test1234.com', null, 'Test', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, 1);

        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findActiveAt')->willReturn([$gline]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline')
            ->with('001', '*', 'test1234.com', 0, 'Test');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteParsesUserAtHostMaskCorrectly(): void
    {
        $gline = new GlineEntry('ident@*.badisp.com', null, 'Test', new DateTimeImmutable('2026-01-01T00:00:00+00:00'), null, 1);

        $glineRepo = $this->createStub(GlineRepository::class);
        $glineRepo->method('findActiveAt')->willReturn([$gline]);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('addGline')
            ->with('001', 'ident', '*.badisp.com', 0, 'Test');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $subscriber = new OperServGlineEnforceSubscriber(
            $glineRepo,
            $connectionHolder,
            new NullLogger(),
        );

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber->onSyncComplete($event);
    }
}
