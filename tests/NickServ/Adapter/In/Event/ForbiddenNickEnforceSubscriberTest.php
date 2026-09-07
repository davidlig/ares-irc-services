<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\NickServ\Adapter\In\Event\ForbiddenNickEnforceSubscriber;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Domain\Entity\RegisteredNick;
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

        self::assertArrayHasKey(UserNicknameChangedEvent::class, $events);
        self::assertSame(['onNickChanged', 10], $events[UserNicknameChangedEvent::class]);
    }

    #[Test]
    public function onNickChangedSkipsDuringBurst(): void
    {
        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(false);

        $subscriber = $this->createSubscriber(burstState: $burstState);

        $event = new UserNicknameChangedEvent('UID123', 'OldNick', 'BadNick');

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

        $event = new UserNicknameChangedEvent('UID123', 'OldNick', 'BadNick');

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
        $forbiddenService->expects(self::once())->method('notifyAndForceGuest')->with('UID123', 'Spam', 'BadNick');

        $subscriber = $this->createSubscriber(
            nickRepository: $nickRepository,
            burstState: $burstState,
            pendingRegistry: $pendingRegistry,
            userLookup: $userLookup,
            forbiddenService: $forbiddenService,
        );

        $event = new UserNicknameChangedEvent('UID123', 'OldNick', 'BadNick');

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onNickChangedDoesNothingWhenNotForbidden(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('findByNick')->with('GoodNick')->willReturn(null);

        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(true);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('peek')->with('UID123')->willReturn(false);

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::never())->method('notifyAndForceGuest');

        $subscriber = $this->createSubscriber(
            nickRepository: $nickRepository,
            burstState: $burstState,
            pendingRegistry: $pendingRegistry,
            forbiddenService: $forbiddenService,
        );

        $event = new UserNicknameChangedEvent('UID123', 'OldNick', 'GoodNick');

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onNickChangedDoesNothingWhenUserNotFound(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('findByNick')->with('BadNick')->willReturn($nick);

        $burstState = $this->createMock(BurstState::class);
        $burstState->expects(self::once())->method('isComplete')->willReturn(true);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('peek')->with('UID123')->willReturn(false);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn(null);

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::never())->method('notifyAndForceGuest');

        $subscriber = $this->createSubscriber(
            nickRepository: $nickRepository,
            burstState: $burstState,
            pendingRegistry: $pendingRegistry,
            userLookup: $userLookup,
            forbiddenService: $forbiddenService,
        );

        $event = new UserNicknameChangedEvent('UID123', 'OldNick', 'BadNick');

        $subscriber->onNickChanged($event);
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
