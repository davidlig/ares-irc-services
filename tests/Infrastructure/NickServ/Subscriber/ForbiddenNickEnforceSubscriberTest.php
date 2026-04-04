<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\NickServ\BurstState;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\NickServ\Service\ForbiddenNickService;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\UserJoinedNetworkDTO;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\Subscriber\ForbiddenNickEnforceSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ForbiddenNickEnforceSubscriber::class)]
final class ForbiddenNickEnforceSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = ForbiddenNickEnforceSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(UserNickChangedEvent::class, $events);
        self::assertArrayHasKey(UserJoinedNetworkAppEvent::class, $events);
        self::assertSame(['onNickChanged', 10], $events[UserNickChangedEvent::class]);
        self::assertSame(['onUserJoined', 10], $events[UserJoinedNetworkAppEvent::class]);
    }

    #[Test]
    public function onNickChangedSkipsDuringBurst(): void
    {
        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(false);

        $subscriber = $this->createSubscriber(burstState: $burstState);

        $event = new UserNickChangedEvent(
            new Uid('UID123'),
            new Nick('OldNick'),
            new Nick('BadNick'),
        );

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onNickChangedSkipsWhenPendingRestore(): void
    {
        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(true);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('peek')->with('UID123')->willReturn(true);

        $subscriber = $this->createSubscriber(burstState: $burstState, pendingRegistry: $pendingRegistry);

        $event = new UserNickChangedEvent(
            new Uid('UID123'),
            new Nick('OldNick'),
            new Nick('BadNick'),
        );

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onNickChangedForcesGuestWhenForbidden(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('findByNick')->with('BadNick')->willReturn($nick);

        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(true);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('peek')->with('UID123')->willReturn(false);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn(
            $this->createSenderView('UID123', 'BadNick')
        );

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('notifyAndForceGuest')->with('UID123', 'Spam');

        $subscriber = $this->createSubscriber(
            nickRepository: $nickRepository,
            burstState: $burstState,
            pendingRegistry: $pendingRegistry,
            userLookup: $userLookup,
            forbiddenService: $forbiddenService,
        );

        $event = new UserNickChangedEvent(
            new Uid('UID123'),
            new Nick('OldNick'),
            new Nick('BadNick'),
        );

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserJoinedSkipsDuringBurst(): void
    {
        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(false);

        $subscriber = $this->createSubscriber(burstState: $burstState);

        $user = $this->createUserDTO('UID123', 'BadNick');
        $event = new UserJoinedNetworkAppEvent($user);

        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function onUserJoinedForcesGuestWhenForbidden(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('findByNick')->with('BadNick')->willReturn($nick);

        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(true);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn(
            $this->createSenderView('UID123', 'BadNick')
        );

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('notifyAndForceGuest')->with('UID123', 'Spam');

        $subscriber = $this->createSubscriber(
            nickRepository: $nickRepository,
            burstState: $burstState,
            userLookup: $userLookup,
            forbiddenService: $forbiddenService,
        );

        $user = $this->createUserDTO('UID123', 'BadNick');
        $event = new UserJoinedNetworkAppEvent($user);

        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function onUserJoinedDoesNothingWhenNotForbidden(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('findByNick')->with('GoodNick')->willReturn(null);

        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(true);

        $subscriber = $this->createSubscriber(nickRepository: $nickRepository, burstState: $burstState);

        $user = $this->createUserDTO('UID123', 'GoodNick');
        $event = new UserJoinedNetworkAppEvent($user);

        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function onUserJoinedDoesNothingWhenUserNotFound(): void
    {
        $nick = RegisteredNick::createForbidden('ForbiddenNick', 'Banned');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('findByNick')->with('ForbiddenNick')->willReturn($nick);

        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(true);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('UID999')->willReturn(null);

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::never())->method('notifyAndForceGuest');

        $subscriber = $this->createSubscriber(
            nickRepository: $nickRepository,
            burstState: $burstState,
            userLookup: $userLookup,
            forbiddenService: $forbiddenService,
        );

        $user = $this->createUserDTO('UID999', 'ForbiddenNick');
        $event = new UserJoinedNetworkAppEvent($user);

        $subscriber->onUserJoined($event);
    }

    private function createSubscriber(
        ?RegisteredNickRepositoryInterface $nickRepository = null,
        ?BurstState $burstState = null,
        ?PendingNickRestoreRegistryInterface $pendingRegistry = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?ForbiddenNickService $forbiddenService = null,
    ): ForbiddenNickEnforceSubscriber {
        return new ForbiddenNickEnforceSubscriber(
            $nickRepository ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $forbiddenService ?? $this->createStub(ForbiddenNickService::class),
            $burstState ?? $this->createStub(BurstState::class),
            $pendingRegistry ?? $this->createStub(PendingNickRestoreRegistryInterface::class),
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function createUserDTO(string $uid, string $nick): UserJoinedNetworkDTO
    {
        return new UserJoinedNetworkDTO(
            uid: $uid,
            nick: $nick,
            ident: 'i',
            hostname: 'h',
            cloakedHost: 'h',
            ipBase64: 'aBcD',
            displayHost: 'h',
            isIdentified: false,
            isOper: false,
            serverSid: 'SID1',
        );
    }

    private function createSenderView(string $uid, string $nick): SenderView
    {
        return new SenderView(
            uid: $uid,
            nick: $nick,
            ident: 'i',
            hostname: 'h',
            cloakedHost: 'h',
            ipBase64: 'aBcD',
            isIdentified: false,
            isOper: false,
            serverSid: 'SID1',
            displayHost: 'h',
            modes: 'i',
        );
    }
}
