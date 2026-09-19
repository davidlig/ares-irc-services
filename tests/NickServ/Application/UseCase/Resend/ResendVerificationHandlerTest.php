<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Resend;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationVerificationStore;
use App\NickServ\Application\Port\Out\ResendMailSender;
use App\NickServ\Application\Port\Out\ResendThrottle;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\UseCase\Resend\ResendVerification;
use App\NickServ\Application\UseCase\Resend\ResendVerificationHandler;
use App\NickServ\Application\UseCase\Resend\ResendVerificationOutcome;
use App\NickServ\Application\UseCase\Resend\ResendVerificationResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ResendVerificationHandler::class)]
#[CoversClass(ResendVerification::class)]
#[CoversClass(ResendVerificationResult::class)]
final class ResendVerificationHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 14:00:00 UTC');
    }

    #[Test]
    public function returnsNoPendingWhenNickNotFound(): void
    {
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('alice')->willReturn(null);

        $handler = $this->createHandler(nickRepo: $nickRepo);
        $result = $handler->handle(new ResendVerification('alice', 'en'));

        self::assertSame(ResendVerificationOutcome::NoPending, $result->outcome);
        self::assertNull($result->email);
    }

    #[Test]
    public function returnsNoPendingWhenNickIsNotPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('alice')->willReturn($account);

        $handler = $this->createHandler(nickRepo: $nickRepo);
        $result = $handler->handle(new ResendVerification('alice', 'en'));

        self::assertSame(ResendVerificationOutcome::NoPending, $result->outcome);
    }

    #[Test]
    public function returnsThrottledWhenCooldownActive(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $throttle = $this->createMock(ResendThrottle::class);
        $throttle->expects(self::once())
            ->method('remainingCooldownSeconds')
            ->with('alice', 60, $this->now)
            ->willReturn(45);

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $handler = $this->createHandler(nickRepo: $nickRepo, throttle: $throttle, clock: $clock);
        $result = $handler->handle(new ResendVerification('alice', 'en'));

        self::assertSame(ResendVerificationOutcome::Throttled, $result->outcome);
        self::assertSame(45, $result->retryAfterSeconds);
    }

    #[Test]
    public function returnsMailDeliveryFailedWhenMailerThrows(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn('alice@example.com');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $throttle = $this->createStub(ResendThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $tokenGen = $this->createStub(VerificationTokenGenerator::class);
        $tokenGen->method('generate')->willReturn('tok123');

        $verificationStore = $this->createMock(RegistrationVerificationStore::class);
        $verificationStore->expects(self::once())
            ->method('store')
            ->with('alice', 'tok123', self::callback(fn (DateTimeImmutable $exp): bool => $this->now->modify('+3600 seconds') == $exp));

        $mailSender = $this->createMock(ResendMailSender::class);
        $mailSender->expects(self::once())
            ->method('sendResend')
            ->with('alice', 'alice@example.com', 'tok123', 'es')
            ->willThrowException(new RuntimeException('SMTP down'));

        $handler = $this->createHandler(
            nickRepo: $nickRepo,
            throttle: $throttle,
            tokenGen: $tokenGen,
            verificationStore: $verificationStore,
            mailSender: $mailSender,
            clock: $clock,
        );

        $result = $handler->handle(new ResendVerification('alice', 'es'));

        self::assertSame(ResendVerificationOutcome::MailDeliveryFailed, $result->outcome);
    }

    #[Test]
    public function succeedsAndRecordsThrottleWhenMailSent(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn('alice@example.com');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $throttle = $this->createMock(ResendThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::once())
            ->method('recordResend')
            ->with('alice', $this->now);

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $tokenGen = $this->createStub(VerificationTokenGenerator::class);
        $tokenGen->method('generate')->willReturn('tok123');

        $verificationStore = $this->createMock(RegistrationVerificationStore::class);
        $verificationStore->expects(self::once())
            ->method('store')
            ->with('alice', 'tok123', self::callback(fn (DateTimeImmutable $exp): bool => $this->now->modify('+1800 seconds') == $exp));

        $mailSender = $this->createMock(ResendMailSender::class);
        $mailSender->expects(self::once())
            ->method('sendResend')
            ->with('alice', 'alice@example.com', 'tok123', 'fr');

        $handler = $this->createHandler(
            nickRepo: $nickRepo,
            throttle: $throttle,
            tokenGen: $tokenGen,
            verificationStore: $verificationStore,
            mailSender: $mailSender,
            clock: $clock,
            tokenTtlSeconds: 1800,
        );

        $result = $handler->handle(new ResendVerification('alice', 'fr'));

        self::assertSame(ResendVerificationOutcome::Success, $result->outcome);
        self::assertSame('alice@example.com', $result->email);
    }

    #[Test]
    public function succeedsWithoutSendingMailWhenEmailIsEmpty(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn(null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $throttle = $this->createMock(ResendThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::once())
            ->method('recordResend')
            ->with('alice', $this->now);

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $tokenGen = $this->createStub(VerificationTokenGenerator::class);
        $tokenGen->method('generate')->willReturn('tok123');

        $verificationStore = $this->createStub(RegistrationVerificationStore::class);

        $mailSender = $this->createMock(ResendMailSender::class);
        $mailSender->expects(self::never())->method('sendResend');

        $handler = $this->createHandler(
            nickRepo: $nickRepo,
            throttle: $throttle,
            tokenGen: $tokenGen,
            verificationStore: $verificationStore,
            mailSender: $mailSender,
            clock: $clock,
        );

        $result = $handler->handle(new ResendVerification('alice', 'fr'));

        self::assertSame(ResendVerificationOutcome::Success, $result->outcome);
        self::assertSame('', $result->email);
    }

    private function createHandler(
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?ResendThrottle $throttle = null,
        ?VerificationTokenGenerator $tokenGen = null,
        ?RegistrationVerificationStore $verificationStore = null,
        ?ResendMailSender $mailSender = null,
        ?Clock $clock = null,
        int $minInterval = 60,
        int $tokenTtlSeconds = 3600,
    ): ResendVerificationHandler {
        return new ResendVerificationHandler(
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $throttle ?? $this->createStub(ResendThrottle::class),
            $tokenGen ?? $this->createStub(VerificationTokenGenerator::class),
            $verificationStore ?? $this->createStub(RegistrationVerificationStore::class),
            $mailSender ?? $this->createStub(ResendMailSender::class),
            $clock ?? $this->createStub(Clock::class),
            $minInterval,
            $tokenTtlSeconds,
        );
    }
}
