<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Resend;

use App\NickServ\Adapter\Out\InMemory\InMemoryTemporaryRescueTokenStore;
use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\ResendMailSender;
use App\NickServ\Application\Port\Out\ResendThrottle;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\UseCase\Resend\ResendVerification;
use App\NickServ\Application\UseCase\Resend\ResendVerificationHandler;
use App\NickServ\Application\UseCase\Resend\ResendVerificationOutcome;
use App\NickServ\Application\UseCase\Resend\ResendVerificationResult;
use App\NickServ\Domain\Entity\NickHistory;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(ResendVerificationHandler::class)]
#[CoversClass(ResendVerification::class)]
#[CoversClass(ResendVerificationResult::class)]
final class ResendVerificationHandlerTest extends TestCase
{
    #[Test]
    public function tokenPolicyDisablesEveryResendBeforeAccountLookup(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::never())->method('findByNick');
        self::assertSame(ResendVerificationOutcome::Disabled, $this->handler($this->account(), mode: RegistrationMode::Token, repo: $repo)->handle($this->input())->outcome);
    }

    #[Test]
    public function missingActiveExpiredAndMissingEmailAccountsCannotResend(): void
    {
        $active = $this->account();
        $active->activate();
        $expired = RegisteredNick::createPending('Alice', 'hash', 'alice@example.com', 'en', $this->now(), $this->now());
        $missingEmail = $this->createStub(RegisteredNick::class);
        $missingEmail->method('isPending')->willReturn(true);
        $missingEmail->method('getExpiresAt')->willReturn($this->now()->modify('+1 hour'));
        foreach ([null, $active, $expired, $missingEmail] as $account) {
            self::assertSame(ResendVerificationOutcome::NoPending, $this->handler($account)->handle($this->input())->outcome);
        }
    }

    #[Test]
    public function throttlePreservedAndReportsRemainingSeconds(): void
    {
        $throttle = $this->createMock(ResendThrottle::class);
        $throttle->expects(self::once())->method('remainingCooldownSeconds')->with('Alice', 60, $this->now())->willReturn(45);
        $result = $this->handler($this->account(), throttle: $throttle)->handle($this->input());
        self::assertSame(ResendVerificationOutcome::Throttled, $result->outcome);
        self::assertSame(45, $result->retryAfterSeconds);
    }

    #[Test]
    public function resendRenewsPendingDeadlineAndMemoryChallengeWithoutPersistingToken(): void
    {
        $account = RegisteredNick::createPending('Alice', 'hash', 'alice@example.com', 'en', $this->now()->modify('+30 seconds'), $this->now());
        new ReflectionProperty($account, 'id')->setValue($account, 42);
        $expiry = $account->getExpiresAt();
        self::assertInstanceOf(DateTimeImmutable::class, $expiry);
        $temporary = new InMemoryTemporaryRescueTokenStore();
        $oldHash = str_repeat('a', 64);
        $temporary->replace(42, $oldHash, $expiry);
        $newToken = null;
        $mail = $this->createMock(ResendMailSender::class);
        $mail->expects(self::once())->method('sendResend')->with('Alice', 'alice@example.com', self::anything(), 'es')->willReturnCallback(function (string $nick, string $email, string $token) use ($temporary, &$newToken): void {
            $newToken = $token;
            $hash = new UuidRescueTokenCodec()->hash($token);
            self::assertIsString($hash);
            self::assertTrue($temporary->matches(42, $hash, $this->now()));
        });
        $throttle = $this->createMock(ResendThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::once())->method('recordResend')->with('Alice', $this->now());
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with($account);
        $historyRepository = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepository->expects(self::once())->method('save')->with(self::callback(static fn (NickHistory $entry): bool => 42 === $entry->getNickId()
            && 'RESEND' === $entry->getAction()
            && 'Alice' === $entry->getPerformedBy()
            && null === $entry->getPerformedByNickId()
            && ['ip' => '192.0.2.2'] === $entry->getExtraData()));
        $result = $this->handler($account, temporary: $temporary, mail: $mail, throttle: $throttle, repo: $repo, history: new NickHistoryService($historyRepository))->handle($this->input());
        self::assertSame(ResendVerificationOutcome::Success, $result->outcome);
        self::assertSame('alice@example.com', $result->email);
        self::assertSame($this->now()->modify('+3600 seconds')->format('c'), $account->getExpiresAt()?->format('c'));
        self::assertTrue($account->isPending());
        self::assertNull($account->getRescueTokenHash());
        self::assertFalse($temporary->matches(42, $oldHash, $this->now()));
        self::assertIsString($newToken);
        $newHash = hash('sha256', strtolower($newToken));
        self::assertTrue($temporary->matches(42, $newHash, $this->now()->modify('+3599 seconds')));
        self::assertFalse($temporary->matches(42, $newHash, $this->now()->modify('+3600 seconds')));
    }

    #[Test]
    public function mailFailureLeavesPendingAndNewMemoryChallengeRetryable(): void
    {
        $account = $this->account();
        $temporary = $this->createMock(TemporaryRescueTokenStore::class);
        $temporary->expects(self::once())->method('prune')->with($this->now());
        $temporary->expects(self::once())->method('replace')->with(42, self::matchesRegularExpression('/^[0-9a-f]{64}$/D'), $account->getExpiresAt());
        $mail = $this->createStub(ResendMailSender::class);
        $mail->method('sendResend')->willThrowException(new RuntimeException('mail failed'));
        $historyRepository = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepository->expects(self::never())->method('save');
        $result = $this->handler($account, temporary: $temporary, mail: $mail, history: new NickHistoryService($historyRepository))->handle($this->input());
        self::assertSame(ResendVerificationOutcome::MailDeliveryFailed, $result->outcome);
        self::assertTrue($account->isPending());
        self::assertNull($account->getRescueTokenHash());
    }

    #[Test]
    public function capacityFailureDoesNotClaimDelivery(): void
    {
        $temporary = $this->createStub(TemporaryRescueTokenStore::class);
        $temporary->method('replace')->willThrowException(new OverflowException());
        $mail = $this->createMock(ResendMailSender::class);
        $mail->expects(self::never())->method('sendResend');
        $account = $this->account();
        $expiry = $account->getExpiresAt();
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $repo->expects(self::never())->method('save');
        self::assertSame(ResendVerificationOutcome::MailDeliveryFailed, $this->handler($account, temporary: $temporary, mail: $mail, repo: $repo)->handle($this->input())->outcome);
        self::assertSame($expiry, $account->getExpiresAt());
    }

    private function input(): ResendVerification
    {
        return new ResendVerification('Alice', 'es', 'Alice', '192.0.2.2');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 14:00:00 UTC');
    }

    private function account(): RegisteredNick
    {
        $account = RegisteredNick::createPending('Alice', 'hash', 'alice@example.com', 'en', $this->now()->modify('+1 hour'), $this->now());
        new ReflectionProperty($account, 'id')->setValue($account, 42);

        return $account;
    }

    private function handler(?RegisteredNick $account, RegistrationMode $mode = RegistrationMode::Email, ?TemporaryRescueTokenStore $temporary = null, ?ResendMailSender $mail = null, ?ResendThrottle $throttle = null, ?RegisteredNickRepositoryInterface $repo = null, ?NickHistoryService $history = null): ResendVerificationHandler
    {
        if (null === $repo) {
            $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
            $repo->method('findByNick')->willReturn($account);
        }
        $policy = $this->createStub(RegistrationModeQuery::class);
        $policy->method('current')->willReturn($mode);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());

        return new ResendVerificationHandler($repo, $throttle ?? $this->createStub(ResendThrottle::class), new UuidRescueTokenCodec(), $temporary ?? new InMemoryTemporaryRescueTokenStore(), $policy, $mail ?? $this->createStub(ResendMailSender::class), $clock, $history ?? new NickHistoryService($this->createStub(NickHistoryRepositoryInterface::class)), 60);
    }
}
