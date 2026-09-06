<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Status;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\UseCase\Status\StatusNick;
use App\NickServ\Application\UseCase\Status\StatusNickHandler;
use App\NickServ\Application\UseCase\Status\StatusNickOutcome;
use App\NickServ\Application\UseCase\Status\StatusNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusNickHandler::class)]
#[CoversClass(StatusNick::class)]
#[CoversClass(StatusNickResult::class)]
final class StatusNickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 15:00:00 UTC');
    }

    #[Test]
    public function returnsUnregisteredOfflineWhenNickNotFoundAndOffline(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('ghost')->willReturn(null);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('ghost', isOnline: false, isIdentified: false));

        self::assertSame(StatusNickOutcome::UnregisteredOffline, $result->outcome);
        self::assertSame('ghost', $result->nickname);
    }

    #[Test]
    public function returnsUnregisteredOnlineWhenNickNotFoundAndOnline(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('guest')->willReturn(null);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('guest', isOnline: true, isIdentified: false));

        self::assertSame(StatusNickOutcome::UnregisteredOnline, $result->outcome);
        self::assertSame('guest', $result->nickname);
    }

    #[Test]
    public function returnsPendingWithRemainingMinutesWhenExpiresAtPresent(): void
    {
        $expiresAt = $this->now->modify('+125 seconds'); // 2.08 minutes -> ceil = 3
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Pending);
        $account->method('getExpiresAt')->willReturn($expiresAt);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $handler = new StatusNickHandler($nickRepo, $clock);
        $result = $handler->handle(new StatusNick('alice', isOnline: false, isIdentified: false));

        self::assertSame(StatusNickOutcome::Pending, $result->outcome);
        self::assertSame('alice', $result->nickname);
        self::assertSame($expiresAt, $result->expiresAt);
        self::assertSame(3, $result->expiresInMinutes);
    }

    #[Test]
    public function returnsPendingWithZeroMinutesWhenExpiresAtNull(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Pending);
        $account->method('getExpiresAt')->willReturn(null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('alice', isOnline: false, isIdentified: false));

        self::assertSame(StatusNickOutcome::Pending, $result->outcome);
        self::assertSame('alice', $result->nickname);
        self::assertNull($result->expiresAt);
        self::assertSame(0, $result->expiresInMinutes);
    }

    #[Test]
    public function returnsRegisteredNotConnectedWhenOffline(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('bob', isOnline: false, isIdentified: false));

        self::assertSame(StatusNickOutcome::RegisteredNotConnected, $result->outcome);
        self::assertSame('bob', $result->nickname);
    }

    #[Test]
    public function returnsRegisteredNotIdentifiedWhenOnlineAndNotIdentified(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('bob', isOnline: true, isIdentified: false));

        self::assertSame(StatusNickOutcome::RegisteredNotIdentified, $result->outcome);
        self::assertSame('bob', $result->nickname);
    }

    #[Test]
    public function returnsRegisteredIdentifiedWhenOnlineAndIdentified(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('bob', isOnline: true, isIdentified: true));

        self::assertSame(StatusNickOutcome::RegisteredIdentified, $result->outcome);
        self::assertSame('bob', $result->nickname);
    }

    #[Test]
    public function returnsSuspendedWithReasonAndUntil(): void
    {
        $until = $this->now->modify('+7 days');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $account->method('getReason')->willReturn('Spam');
        $account->method('getSuspendedUntil')->willReturn($until);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('charlie', isOnline: false, isIdentified: false));

        self::assertSame(StatusNickOutcome::Suspended, $result->outcome);
        self::assertSame('charlie', $result->nickname);
        self::assertSame('Spam', $result->reason);
        self::assertSame($until, $result->suspendedUntil);
    }

    #[Test]
    public function returnsForbiddenWithReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Forbidden);
        $account->method('getReason')->willReturn('Reserved nick');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('root', isOnline: false, isIdentified: false));

        self::assertSame(StatusNickOutcome::Forbidden, $result->outcome);
        self::assertSame('root', $result->nickname);
        self::assertSame('Reserved nick', $result->reason);
    }

    #[Test]
    public function returnsPendingDeletionWithScheduledDate(): void
    {
        $deletionDate = $this->now->modify('+24 hours');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::PendingDeletion);
        $account->method('getPendingDeletionAt')->willReturn($deletionDate);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $handler = new StatusNickHandler($nickRepo, $this->createStub(Clock::class));
        $result = $handler->handle(new StatusNick('olduser', isOnline: false, isIdentified: false));

        self::assertSame(StatusNickOutcome::PendingDeletion, $result->outcome);
        self::assertSame('olduser', $result->nickname);
        self::assertSame($deletionDate, $result->pendingDeletionAt);
    }
}
