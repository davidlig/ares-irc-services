<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Resend;

use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Application\Port\Out\ResendMailSender;
use App\NickServ\Application\Port\Out\ResendThrottle;
use App\NickServ\Application\UseCase\Resend\ResendVerification;
use App\NickServ\Application\UseCase\Resend\ResendVerificationHandler;
use App\NickServ\Application\UseCase\Resend\ResendVerificationOutcome;
use App\NickServ\Application\UseCase\Resend\ResendVerificationResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(ResendVerificationHandler::class)]
#[CoversClass(ResendVerification::class)]
#[CoversClass(ResendVerificationResult::class)]
final class ResendVerificationHandlerTest extends TestCase
{
    public function testTokenPolicyDisablesEveryResendBeforeAccountLookup(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::never())->method('findByNick');
        self::assertSame(ResendVerificationOutcome::Disabled, $this->handler($this->account(), mode: RegistrationMode::Token, repo: $repo)->handle($this->input())->outcome);
    }

    public function testPendingTokenAlwaysDisablesResendEvenUnderEmailPolicy(): void
    {
        self::assertSame(ResendVerificationOutcome::Disabled, $this->handler($this->account(RegistrationMode::Token))->handle($this->input())->outcome);
    }

    public function testMissingActiveExpiredAndMissingEmailAccountsCannotResend(): void
    {
        $active = $this->account();
        $active->activate();
        $expired = RegisteredNick::createPending('Alice', 'hash', 'alice@example.com', 'en', $this->now(), $this->now());
        $missingEmail = $this->createStub(RegisteredNick::class);
        $missingEmail->method('isPending')->willReturn(true);
        $missingEmail->method('getRegistrationMode')->willReturn(RegistrationMode::Email);
        $missingEmail->method('getExpiresAt')->willReturn($this->now()->modify('+1 hour'));
        foreach ([null, $active, $expired, $missingEmail] as $account) {
            self::assertSame(ResendVerificationOutcome::NoPending, $this->handler($account)->handle($this->input())->outcome);
        }
    }

    public function testThrottlePreservedAndReportsRemainingSeconds(): void
    {
        $throttle = $this->createMock(ResendThrottle::class);
        $throttle->expects(self::once())->method('remainingCooldownSeconds')->with('Alice', 60, $this->now())->willReturn(45);
        $result = $this->handler($this->account(), throttle: $throttle)->handle($this->input());
        self::assertSame(ResendVerificationOutcome::Throttled, $result->outcome);
        self::assertSame(45, $result->retryAfterSeconds);
    }

    public function testPendingEmailHashIsReplacedBeforeMailWithoutExtendingExpiry(): void
    {
        $account = $this->account();
        $account->replaceRescueTokenHash(str_repeat('a', 64));
        $expiry = $account->getExpiresAt();
        $persisted = false;
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::once())->method('replacePending')->with(42, str_repeat('a', 64), self::matchesRegularExpression('/^[0-9a-f]{64}$/D'), $this->now())->willReturnCallback(static function () use (&$persisted): bool {
            $persisted = true;

            return true;
        });
        $mail = $this->createMock(ResendMailSender::class);
        $mail->expects(self::once())->method('sendResend')->with('Alice', 'alice@example.com', self::matchesRegularExpression('/^[0-9a-f-]{36}$/D'), 'es')->willReturnCallback(static function () use (&$persisted): void {
            self::assertTrue($persisted);
        });
        $throttle = $this->createMock(ResendThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::once())->method('recordResend')->with('Alice', $this->now());
        $result = $this->handler($account, credentials: $credentials, mail: $mail, throttle: $throttle)->handle($this->input());
        self::assertSame(ResendVerificationOutcome::Success, $result->outcome);
        self::assertSame('alice@example.com', $result->email);
        self::assertSame($expiry, $account->getExpiresAt());
    }

    public function testMailFailureIsHonestAndLeavesPendingRetryable(): void
    {
        $account = $this->account();
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::once())->method('replacePending')->with(42, null, self::anything(), $this->now())->willReturn(true);
        $mail = $this->createStub(ResendMailSender::class);
        $mail->method('sendResend')->willThrowException(new RuntimeException('mail failed'));
        $result = $this->handler($account, credentials: $credentials, mail: $mail)->handle($this->input());
        self::assertSame(ResendVerificationOutcome::MailDeliveryFailed, $result->outcome);
        self::assertTrue($account->isPending());
    }

    public function testConcurrentActivationOrReplacementCannotSendAnUncommittedToken(): void
    {
        $mail = $this->createMock(ResendMailSender::class);
        $mail->expects(self::never())->method('sendResend');
        self::assertSame(ResendVerificationOutcome::NoPending, $this->handler($this->account(), mail: $mail)->handle($this->input())->outcome);
    }

    private function input(): ResendVerification
    {
        return new ResendVerification('Alice', 'es');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 14:00:00 UTC');
    }

    private function account(RegistrationMode $mode = RegistrationMode::Email): RegisteredNick
    {
        $account = RegisteredNick::createPending('Alice', 'hash', 'alice@example.com', 'en', $this->now()->modify('+1 hour'), $this->now(), $mode);
        new ReflectionProperty($account, 'id')->setValue($account, 42);

        return $account;
    }

    private function handler(?RegisteredNick $account, RegistrationMode $mode = RegistrationMode::Email, ?RescueCredentialPersistence $credentials = null, ?ResendMailSender $mail = null, ?ResendThrottle $throttle = null, ?RegisteredNickRepositoryInterface $repo = null): ResendVerificationHandler
    {
        if (null === $repo) {
            $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
            $repo->method('findByNick')->willReturn($account);
        }
        $policy = $this->createStub(RegistrationModeQuery::class);
        $policy->method('current')->willReturn($mode);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());

        return new ResendVerificationHandler($repo, $throttle ?? $this->createStub(ResendThrottle::class), new UuidRescueTokenCodec(), $credentials ?? $this->createStub(RescueCredentialPersistence::class), $policy, $mail ?? $this->createStub(ResendMailSender::class), $clock, 60);
    }
}
