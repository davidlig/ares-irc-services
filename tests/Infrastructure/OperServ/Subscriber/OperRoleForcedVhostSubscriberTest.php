<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\OperServ\ForcedVhostApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\OperServ\Subscriber\OperRoleForcedVhostSubscriber;
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
        $role->setForcedVhostPattern('admin.network');
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getId')->willReturn(123);
        $nick->method('getNickname')->willReturn('davidlig');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'davidlig');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'davidlig.admin.network', '001');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

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
