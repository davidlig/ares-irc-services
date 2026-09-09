<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\OperServ\Adapter\In\Event\ForcedVhostApplier;
use App\OperServ\Adapter\In\Event\OperRoleForcedVhostSubscriber;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OperRoleForcedVhostSubscriber::class)]
final class OperRoleForcedVhostSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsNickIdentifiedEvent(): void
    {
        $events = OperRoleForcedVhostSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NickIdentifiedEvent::class, $events);
        self::assertSame(['onNickIdentified', 0], $events[NickIdentifiedEvent::class]);
    }

    #[Test]
    public function onNickIdentifiedCallsApplyForcedVhost(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->changeForcedVhostPattern('admin.network');
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $nick = new NickProjection(123, 'davidlig', 'hash', null);

        $nickRepo = $this->createStub(NickProjectionQuery::class);
        $nickRepo->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'davidlig');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'davidlig.admin.network', '001');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new VhostDisplayResolver(),
            new NullLogger(),
        );

        $subscriber = new OperRoleForcedVhostSubscriber($applier);

        $event = new NickIdentifiedEvent(123, 'davidlig', 'UID1');
        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function onNickIdentifiedDoesNothingWhenNotIrcop(): void
    {
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $nickRepo = $this->createStub(NickProjectionQuery::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new VhostDisplayResolver(),
            new NullLogger(),
        );

        $subscriber = new OperRoleForcedVhostSubscriber($applier);

        $event = new NickIdentifiedEvent(123, 'TestNick', 'UID1');
        $subscriber->onNickIdentified($event);
    }
}
