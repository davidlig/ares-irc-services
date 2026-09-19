<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\NicknameReservation;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\NickForceService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForbiddenNickService::class)]
final class ForbiddenNickServiceTest extends TestCase
{
    #[Test]
    public function forbidCreatesNewForbiddenNick(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::once())->method('reserve')
            ->with('BadNick', 'Spam');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            forceService: $forceService,
            reservation: $reservation,
        );

        $result = $forbiddenService->forbid('BadNick', 'Spam', 'Admin');

        self::assertSame('BadNick', $result->getNickname());
        self::assertTrue($result->isForbidden());
        self::assertSame('Spam', $result->getReason());
    }

    #[Test]
    public function forbidDropsExistingAccountBeforeCreatingForbidden(): void
    {
        $existingNick = RegisteredNick::createPending(
            'BadUser',
            'hash',
            'test@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $existingNick->activate();

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($existingNick);
        $nickRepository->expects(self::once())->method('save');

        $forbiddenService = $this->createService(nickRepository: $nickRepository);

        $result = $forbiddenService->forbid('BadUser', 'Spam', 'Admin');

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function forbidForcesRenameIfUserOnline(): void
    {
        $onlineUser = new NetworkUser('UID123', 'BadNick', 'i', 'h', 'c', 'aBcD', false, false, 'SID1', 'h', 'o');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID123', null, 'forbidden-nick');

        $notifier = $this->createMock(NickProtectionNotifier::class);
        $notifier->expects(self::once())->method('notifyForbidden');

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::exactly(2))->method('reserve');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            reservation: $reservation,
        );

        $forbiddenService->forbid('BadNick', 'Spam', 'Admin');
    }

    #[Test]
    public function forbidAppliesNickReservation(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $nickRepository->expects(self::once())->method('save');

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::once())->method('reserve')
            ->with('BadNick', 'Spamming network');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $forbiddenService->forbid('BadNick', 'Spamming network', 'Admin');
    }

    #[Test]
    public function updateReasonUpdatesForbiddenNick(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Old reason');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::once())->method('reserve')
            ->with('BadNick', 'New reason');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $forbiddenService->updateReason($nick, 'New reason');

        self::assertSame('New reason', $nick->getReason());
    }

    #[Test]
    public function updateReasonForcesRenameIfUserOnline(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Old reason');
        $onlineUser = new NetworkUser('UID123', 'BadNick', 'i', 'h', 'c', 'aBcD', false, false, 'SID1', 'h', 'o');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())->method('save');

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick');

        $notifier = $this->createMock(NickProtectionNotifier::class);
        $notifier->expects(self::once())->method('notifyForbidden');

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::exactly(2))->method('reserve');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            reservation: $reservation,
        );

        $forbiddenService->updateReason($nick, 'New reason');
    }

    #[Test]
    public function unforbidReturnsTrueWhenForbidden(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::once())->method('release')
            ->with('BadNick');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $result = $forbiddenService->unforbid('BadNick');

        self::assertTrue($result);
    }

    #[Test]
    public function unforbidRemovesNickReservation(): void
    {
        $nick = RegisteredNick::createForbidden('BadNick', 'Spam');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::once())->method('release')
            ->with('BadNick');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $forbiddenService->unforbid('BadNick');
    }

    #[Test]
    public function unforbidReturnsFalseWhenNotForbidden(): void
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::never())->method('release');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $result = $forbiddenService->unforbid('SomeNick');

        self::assertFalse($result);
    }

    #[Test]
    public function unforbidReturnsFalseWhenRegisteredNotForbidden(): void
    {
        $nick = RegisteredNick::createPending(
            'SomeNick',
            'hash',
            'email@test.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $nick->activate();

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::never())->method('release');

        $forbiddenService = $this->createService(
            nickRepository: $nickRepository,
            reservation: $reservation,
        );

        $result = $forbiddenService->unforbid('SomeNick');

        self::assertFalse($result);
    }

    #[Test]
    public function notifyAndForceGuestFetchesNicknameFromUserLookupWhenNull(): void
    {
        $onlineUser = new NetworkUser('UID123', 'BadNick', 'i', 'h', 'c', 'aBcD', false, false, 'SID1', 'h', 'o');

        $userLookup = $this->createMock(NickNetworkUserLookup::class);
        $userLookup->expects(self::once())->method('findByUid')->with('UID123')->willReturn($onlineUser);

        $notifier = $this->createMock(NickProtectionNotifier::class);
        $notifier->expects(self::once())->method('notifyForbidden')->with('UID123', 'BadNick', 'Spam reason', 'en');

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID123', null, 'forbidden-nick');

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::once())->method('reserve');

        $forbiddenService = $this->createService(
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            reservation: $reservation,
        );

        $forbiddenService->notifyAndForceGuest('UID123', 'Spam reason', null);
    }

    #[Test]
    public function notifyAndForceGuestUsesUnknownWhenUserNotFound(): void
    {
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn(null);

        $notifier = $this->createMock(NickProtectionNotifier::class);
        $notifier->expects(self::once())->method('notifyForbidden')->with('UID123', 'Unknown', 'Spam reason', 'en');

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick');

        $reservation = $this->createMock(NicknameReservation::class);
        $reservation->expects(self::once())->method('reserve');

        $forbiddenService = $this->createService(
            forceService: $forceService,
            userLookup: $userLookup,
            notifier: $notifier,
            reservation: $reservation,
        );

        $forbiddenService->notifyAndForceGuest('UID123', 'Spam reason', null);
    }

    private function createService(
        ?RegisteredNickRepositoryInterface $nickRepository = null,
        ?NickForceService $forceService = null,
        ?NickNetworkUserLookup $userLookup = null,
        ?NickProtectionNotifier $notifier = null,
        ?NicknameReservation $reservation = null,
    ): ForbiddenNickService {
        return new ForbiddenNickService(
            $nickRepository ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $forceService ?? $this->createStub(NickForceService::class),
            $userLookup ?? $this->createStub(NickNetworkUserLookup::class),
            $notifier ?? $this->createStub(NickProtectionNotifier::class),
            $reservation ?? $this->createStub(NicknameReservation::class),
            $this->createStub(NickServActivitySink::class),
            'en',
        );
    }
}
