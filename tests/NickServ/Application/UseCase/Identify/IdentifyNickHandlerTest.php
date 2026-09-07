<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Identify;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\IdentifyEventPublisher;
use App\NickServ\Application\Port\Out\IdentifyLockoutTracker;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\UseCase\Identify\IdentifyNick;
use App\NickServ\Application\UseCase\Identify\IdentifyNickHandler;
use App\NickServ\Application\UseCase\Identify\IdentifyNickOutcome;
use App\NickServ\Application\UseCase\Identify\IdentifyNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifyNickHandler::class)]
#[CoversClass(IdentifyNick::class)]
#[CoversClass(IdentifyNickResult::class)]
final class IdentifyNickHandlerTest extends TestCase
{
    #[Test]
    public function returnsAlreadyIdentifiedWhenFoundInTracker(): void
    {
        $sessionTracker = $this->createMock(IdentifiedSessionTracker::class);
        $sessionTracker->expects(self::once())
            ->method('findNick')
            ->with('UID1')
            ->willReturn('alice');

        $handler = $this->createHandler(sessionTracker: $sessionTracker);
        $result = $handler->handle(new IdentifyNick(
            senderUid: 'UID1',
            senderNick: 'alice',
            senderIsIdentified: false,
            nickname: 'ALICE',
            password: 'pass',
            clientKey: 'ip:127.0.0.1',
        ));

        self::assertSame(IdentifyNickOutcome::AlreadyIdentified, $result->outcome);
        self::assertSame('ALICE', $result->nickname);
    }

    #[Test]
    public function returnsAlreadyIdentifiedWhenSenderIsIdentifiedAndRegistersSession(): void
    {
        $sessionTracker = $this->createMock(IdentifiedSessionTracker::class);
        $sessionTracker->expects(self::once())
            ->method('findNick')
            ->with('UID1')
            ->willReturn(null);
        $sessionTracker->expects(self::once())
            ->method('register')
            ->with('UID1', 'alice');

        $handler = $this->createHandler(sessionTracker: $sessionTracker);
        $result = $handler->handle(new IdentifyNick(
            senderUid: 'UID1',
            senderNick: 'ALICE',
            senderIsIdentified: true,
            nickname: 'alice',
            password: 'pass',
            clientKey: 'ip:127.0.0.1',
        ));

        self::assertSame(IdentifyNickOutcome::AlreadyIdentified, $result->outcome);
        self::assertSame('alice', $result->nickname);
    }

    #[Test]
    public function returnsLockedOutWhenRemainingSecondsPositive(): void
    {
        $sessionTracker = $this->createStub(IdentifiedSessionTracker::class);
        $sessionTracker->method('findNick')->willReturn(null);

        $lockoutTracker = $this->createMock(IdentifyLockoutTracker::class);
        $lockoutTracker->expects(self::once())
            ->method('getRemainingLockoutSeconds')
            ->with('ip:127.0.0.1', 5, 900, 1800, $this->now())
            ->willReturn(120);

        $handler = $this->createHandler(sessionTracker: $sessionTracker, lockoutTracker: $lockoutTracker);
        $result = $handler->handle(new IdentifyNick(
            senderUid: 'UID1',
            senderNick: 'alice',
            senderIsIdentified: false,
            nickname: 'alice',
            password: 'pass',
            clientKey: 'ip:127.0.0.1',
        ));

        self::assertSame(IdentifyNickOutcome::LockedOut, $result->outcome);
        self::assertSame(120, $result->retryAfterSeconds);
    }

    #[Test]
    public function returnsNotRegisteredWhenNickNotFound(): void
    {
        $sessionTracker = $this->createStub(IdentifiedSessionTracker::class);
        $sessionTracker->method('findNick')->willReturn(null);

        $lockoutTracker = $this->createStub(IdentifyLockoutTracker::class);
        $lockoutTracker->method('getRemainingLockoutSeconds')->willReturn(0);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())
            ->method('findByNick')
            ->with('bob')
            ->willReturn(null);

        $handler = $this->createHandler(nickRepo: $nickRepo, sessionTracker: $sessionTracker, lockoutTracker: $lockoutTracker);
        $result = $handler->handle(new IdentifyNick(
            senderUid: 'UID1',
            senderNick: 'bob',
            senderIsIdentified: false,
            nickname: 'bob',
            password: 'pass',
            clientKey: 'ip:127.0.0.1',
        ));

        self::assertSame(IdentifyNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('bob', $result->nickname);
    }

    #[Test]
    public function returnsPendingWhenNickIsPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);

        $handler = $this->createHandlerForAccount($account);
        $result = $handler->handle($this->createCommand('alice'));

        self::assertSame(IdentifyNickOutcome::Pending, $result->outcome);
        self::assertSame('alice', $result->nickname);
    }

    #[Test]
    public function returnsSuspendedWhenNickIsSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(true);
        $account->method('getReason')->willReturn('Abuse');

        $handler = $this->createHandlerForAccount($account);
        $result = $handler->handle($this->createCommand('alice'));

        self::assertSame(IdentifyNickOutcome::Suspended, $result->outcome);
        self::assertSame('alice', $result->nickname);
        self::assertSame('Abuse', $result->reason);
    }

    #[Test]
    public function returnsForbiddenWhenNickIsForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);

        $handler = $this->createHandlerForAccount($account);
        $result = $handler->handle($this->createCommand('alice'));

        self::assertSame(IdentifyNickOutcome::Forbidden, $result->outcome);
        self::assertSame('alice', $result->nickname);
    }

    #[Test]
    public function returnsPendingDeletionWhenNickIsPendingDeletion(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(true);

        $handler = $this->createHandlerForAccount($account);
        $result = $handler->handle($this->createCommand('alice'));

        self::assertSame(IdentifyNickOutcome::PendingDeletion, $result->outcome);
        self::assertSame('alice', $result->nickname);
    }

    #[Test]
    public function recordsFailedAttemptAndReturnsInvalidCredentialsWhenPasswordMismatch(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('getPasswordHash')->willReturn('hash');

        $passwordHasher = $this->createMock(PasswordHasher::class);
        $passwordHasher->expects(self::once())->method('verify')->with('wrongpass', 'hash')->willReturn(false);

        $lockoutTracker = $this->createMock(IdentifyLockoutTracker::class);
        $lockoutTracker->method('getRemainingLockoutSeconds')->willReturn(0);
        $lockoutTracker->expects(self::once())
            ->method('recordFailedAttempt')
            ->with('ip:127.0.0.1', 900, $this->now());

        $handler = $this->createHandlerForAccount($account, lockoutTracker: $lockoutTracker, passwordHasher: $passwordHasher);
        $result = $handler->handle($this->createCommand('alice', 'wrongpass'));

        self::assertSame(IdentifyNickOutcome::InvalidCredentials, $result->outcome);
    }

    #[Test]
    public function succeedsWithVhostWhenNotForced(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('Alice');
        $account->method('getVhost')->willReturn('custom.host');
        $account->method('getPasswordHash')->willReturn('$2y$hash');
        $account->method('getLanguage')->willReturn('es');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('alice')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);

        $sessionTracker = $this->createMock(IdentifiedSessionTracker::class);
        $sessionTracker->method('findNick')->willReturn(null);
        $sessionTracker->expects(self::once())->method('register')->with('UID1', 'Alice');

        $lockoutTracker = $this->createMock(IdentifyLockoutTracker::class);
        $lockoutTracker->method('getRemainingLockoutSeconds')->willReturn(0);
        $lockoutTracker->expects(self::once())->method('clearFailedAttempts')->with('ip:127.0.0.1');

        $forcedVhostChecker = $this->createMock(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->expects(self::once())->method('hasForcedVhost')->with(10)->willReturn(false);

        $eventPublisher = $this->createMock(IdentifyEventPublisher::class);
        $eventPublisher->expects(self::exactly(2))->method('publish');

        $handler = new IdentifyNickHandler(
            $nickRepo,
            $sessionTracker,
            $lockoutTracker,
            $eventPublisher,
            new VhostDisplayResolver('net'),
            $forcedVhostChecker,
            $this->passwordHasherThatVerifies('$2y$hash'),
            $this->fixedClock(),
            5,
            900,
            1800,
        );

        $result = $handler->handle($this->createCommand('alice', 'goodpass'));

        self::assertSame(IdentifyNickOutcome::Success, $result->outcome);
        self::assertSame('Alice', $result->nickname);
        self::assertSame('custom.host.net', $result->displayVhost);
        self::assertSame('es', $result->accountLanguage);
    }

    #[Test]
    public function succeedsWithoutVhostWhenForced(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPendingDeletion')->willReturn(false);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('Alice');
        $account->method('getPasswordHash')->willReturn('$2y$hash');
        $account->method('getLanguage')->willReturn('en');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $sessionTracker = $this->createStub(IdentifiedSessionTracker::class);
        $sessionTracker->method('findNick')->willReturn(null);

        $lockoutTracker = $this->createStub(IdentifyLockoutTracker::class);
        $lockoutTracker->method('getRemainingLockoutSeconds')->willReturn(0);

        $forcedVhostChecker = $this->createMock(ForcedVhostCheckerInterface::class);
        $forcedVhostChecker->expects(self::once())->method('hasForcedVhost')->with(10)->willReturn(true);

        $eventPublisher = $this->createStub(IdentifyEventPublisher::class);

        $handler = new IdentifyNickHandler(
            $nickRepo,
            $sessionTracker,
            $lockoutTracker,
            $eventPublisher,
            new VhostDisplayResolver(),
            $forcedVhostChecker,
            $this->passwordHasherThatVerifies('$2y$hash'),
            $this->fixedClock(),
            5,
            900,
            1800,
        );

        $result = $handler->handle($this->createCommand('alice', 'goodpass'));

        self::assertSame(IdentifyNickOutcome::Success, $result->outcome);
        self::assertSame('Alice', $result->nickname);
        self::assertNull($result->displayVhost);
        self::assertSame('en', $result->accountLanguage);
    }

    private function createCommand(string $nickname, string $password = 'secret'): IdentifyNick
    {
        return new IdentifyNick(
            senderUid: 'UID1',
            senderNick: 'random',
            senderIsIdentified: false,
            nickname: $nickname,
            password: $password,
            clientKey: 'ip:127.0.0.1',
        );
    }

    private function createHandlerForAccount(
        RegisteredNick $account,
        ?IdentifyLockoutTracker $lockoutTracker = null,
        ?PasswordHasher $passwordHasher = null,
    ): IdentifyNickHandler {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $sessionTracker = $this->createStub(IdentifiedSessionTracker::class);
        $sessionTracker->method('findNick')->willReturn(null);

        $defaultLockout = $this->createStub(IdentifyLockoutTracker::class);
        $defaultLockout->method('getRemainingLockoutSeconds')->willReturn(0);

        return $this->createHandler(
            nickRepo: $nickRepo,
            sessionTracker: $sessionTracker,
            lockoutTracker: $lockoutTracker ?? $defaultLockout,
            passwordHasher: $passwordHasher,
        );
    }

    private function createHandler(
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?IdentifiedSessionTracker $sessionTracker = null,
        ?IdentifyLockoutTracker $lockoutTracker = null,
        ?IdentifyEventPublisher $eventPublisher = null,
        ?ForcedVhostCheckerInterface $forcedVhostChecker = null,
        ?PasswordHasher $passwordHasher = null,
    ): IdentifyNickHandler {
        return new IdentifyNickHandler(
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $sessionTracker ?? $this->createStub(IdentifiedSessionTracker::class),
            $lockoutTracker ?? $this->createStub(IdentifyLockoutTracker::class),
            $eventPublisher ?? $this->createStub(IdentifyEventPublisher::class),
            new VhostDisplayResolver(),
            $forcedVhostChecker ?? $this->createStub(ForcedVhostCheckerInterface::class),
            $passwordHasher ?? $this->createStub(PasswordHasher::class),
            $this->fixedClock(),
            5,
            900,
            1800,
        );
    }

    private function passwordHasherThatVerifies(string $hash): PasswordHasher
    {
        $passwordHasher = $this->createMock(PasswordHasher::class);
        $passwordHasher->expects(self::once())->method('verify')->with('goodpass', $hash)->willReturn(true);

        return $passwordHasher;
    }

    private function fixedClock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());

        return $clock;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
