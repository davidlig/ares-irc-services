<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Verify;

use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\InMemoryRescueAttemptLimiter;
use App\NickServ\Adapter\Out\InMemory\InMemoryTemporaryRescueTokenStore;
use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RescueAttemptLimiter;
use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\UseCase\Verify\VerifyNick;
use App\NickServ\Application\UseCase\Verify\VerifyNickHandler;
use App\NickServ\Application\UseCase\Verify\VerifyNickOutcome;
use App\NickServ\Application\UseCase\Verify\VerifyNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

#[CoversClass(VerifyNickHandler::class)]
#[CoversClass(VerifyNick::class)]
#[CoversClass(VerifyNickResult::class)]
final class VerifyNickHandlerTest extends TestCase
{
    private const string TOKEN = '12345678-1234-4234-8234-123456789abc';

    private InMemoryTemporaryRescueTokenStore $temporary;

    private UuidRescueTokenCodec $codec;

    private IdentifiedSessionRegistry $sessions;

    protected function setUp(): void
    {
        $this->temporary = new InMemoryTemporaryRescueTokenStore();
        $this->codec = new UuidRescueTokenCodec();
        $this->sessions = new IdentifiedSessionRegistry();
    }

    public function testNoPendingForMissingDisabledOrAlreadyConfirmedAccounts(): void
    {
        $confirmed = $this->account();
        $confirmed->activate();
        $confirmed->replaceRescueTokenHash(str_repeat('a', 64));
        $disabled = $this->account();
        $disabled->suspend('disabled');
        foreach ([null, $confirmed, $disabled] as $account) {
            self::assertSame(VerifyNickOutcome::NoPending, $this->handler($account)->handle($this->input())->outcome);
        }
    }

    public function testTokenRegistrationActivatesThroughCasAndPublishesExistingPasswordEvent(): void
    {
        $account = $this->account(RegistrationMode::Token);
        $hash = $this->challenge();
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::once())->method('confirm')->with(42, NickStatus::Pending, null, $hash, $this->now())->willReturn(true);
        $publisher = $this->createMock(RegistrationEventPublisher::class);
        $publisher->expects(self::once())->method('publish')->with(self::callback(static fn (NickPasswordHashAvailable $event): bool => 42 === $event->nickId && 'password-hash' === $event->passwordHash));
        $result = $this->handler($account, $persistence, publisher: $publisher)->handle($this->input());
        self::assertSame(VerifyNickOutcome::Success, $result->outcome);
        self::assertSame('Alice', $result->nickname);
        self::assertSame('Alice', $this->sessions->findNick('UID1'));
        self::assertFalse($this->temporary->matches(42, $hash, $this->now()));
    }

    public function testEmailHashSurvivesVerificationAndNativeModeDoesNotIdentify(): void
    {
        $account = $this->account();
        $hash = $this->codec->hash(self::TOKEN);
        self::assertIsString($hash);
        $account->replaceRescueTokenHash($hash);
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::once())->method('confirm')->with(42, NickStatus::Pending, $hash, $hash, $this->now())->willReturn(true);
        $result = $this->handler($account, $persistence, mode: NicknameAuthenticationMode::NativeNick)->handle($this->input());
        self::assertSame(VerifyNickOutcome::SuccessNativeAuthenticationRequired, $result->outcome);
        self::assertNull($this->sessions->findNick('UID1'));
        self::assertSame($hash, $account->getRescueTokenHash());
    }

    public function testLegacyConfirmationRequiresSameAuthenticatedAccountAndDoesNotRepeatActivation(): void
    {
        $account = $this->account();
        $account->activate();
        $hash = $this->challenge();
        self::assertSame(VerifyNickOutcome::InvalidToken, $this->handler($account)->handle($this->input())->outcome);
        $publisher = $this->createMock(RegistrationEventPublisher::class);
        $publisher->expects(self::never())->method('publish');
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::once())->method('confirm')->with(42, NickStatus::Registered, null, $hash, $this->now())->willReturn(true);
        $result = $this->handler($account, $persistence, publisher: $publisher)->handle($this->input(authenticatedAccountId: 42));
        self::assertSame(VerifyNickOutcome::CredentialConfirmed, $result->outcome);
        self::assertNull($this->sessions->findNick('UID1'));
        self::assertTrue($account->isRegistered());
    }

    public function testLegacySessionTrackerCanAuthorizeMatchingAccount(): void
    {
        $account = $this->account();
        $account->activate();
        $this->challenge();
        $this->sessions->register('UID1', 'ALICE');
        $store = $this->createStub(RescueCredentialPersistence::class);
        $store->method('confirm')->willReturn(true);
        self::assertSame(VerifyNickOutcome::CredentialConfirmed, $this->handler($account, $store)->handle($this->input())->outcome);
    }

    public function testMalformedStaleMissingAndExpiredChallengesAreRejectedWithoutPersistence(): void
    {
        $account = $this->account(RegistrationMode::Token);
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::never())->method('confirm');
        $handler = $this->handler($account, $persistence);
        self::assertSame(VerifyNickOutcome::InvalidToken, $handler->handle($this->input('bad'))->outcome);
        self::assertSame(VerifyNickOutcome::InvalidToken, $handler->handle($this->input())->outcome);
        $hash = $this->challenge();
        $this->temporary->replace(42, str_repeat('b', 64), $this->now()->modify('+1 hour'));
        self::assertSame(VerifyNickOutcome::InvalidToken, $handler->handle($this->input())->outcome);
        $this->temporary->replace(42, $hash, $this->now());
        self::assertSame(VerifyNickOutcome::InvalidToken, $handler->handle($this->input())->outcome);
        $expired = RegisteredNick::createPending('Alice', 'password', null, 'en', $this->now(), $this->now(), RegistrationMode::Token);
        new ReflectionProperty($expired, 'id')->setValue($expired, 42);
        self::assertSame(VerifyNickOutcome::InvalidToken, $this->handler($expired, $persistence)->handle($this->input())->outcome);
    }

    public function testOldEmailPendingWithoutHashCannotUseLegacyEphemeralToken(): void
    {
        $this->challenge();
        self::assertSame(VerifyNickOutcome::InvalidToken, $this->handler($this->account())->handle($this->input())->outcome);
    }

    public function testCasFailureRetainsChallengeAndReturnsInvalid(): void
    {
        $hash = $this->challenge();
        $result = $this->handler($this->account(RegistrationMode::Token))->handle($this->input());
        self::assertSame(VerifyNickOutcome::InvalidToken, $result->outcome);
        self::assertTrue($this->temporary->matches(42, $hash, $this->now()));
    }

    public function testStorageFailureDoesNotConsumeChallenge(): void
    {
        $hash = $this->challenge();
        $store = $this->createStub(RescueCredentialPersistence::class);
        $store->method('confirm')->willThrowException(new RuntimeException('database failed'));
        try {
            $this->handler($this->account(RegistrationMode::Token), $store)->handle($this->input());
            self::fail('Storage failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('database failed', $exception->getMessage());
            self::assertTrue($this->temporary->matches(42, $hash, $this->now()));
        }
    }

    public function testSharedLimiterBlocksBeforeTokenValidation(): void
    {
        $limiter = $this->createStub(RescueAttemptLimiter::class);
        $limiter->method('remainingCooldownSeconds')->willReturn(123);
        $result = $this->handler($this->account(), limiter: $limiter)->handle($this->input());
        self::assertSame(VerifyNickOutcome::LockedOut, $result->outcome);
        self::assertSame(123, $result->retryAfterSeconds);
    }

    private function challenge(): string
    {
        $hash = $this->codec->hash(self::TOKEN);
        self::assertIsString($hash);
        $this->temporary->replace(42, $hash, $this->now()->modify('+1 hour'));

        return $hash;
    }

    private function account(RegistrationMode $mode = RegistrationMode::Email): RegisteredNick
    {
        $account = RegisteredNick::createPending('Alice', 'password-hash', 'alice@example.com', 'en', $this->now()->modify('+1 hour'), $this->now(), $mode);
        new ReflectionProperty($account, 'id')->setValue($account, 42);

        return $account;
    }

    private function input(string $token = self::TOKEN, ?int $authenticatedAccountId = null): VerifyNick
    {
        return new VerifyNick('Alice', $token, 'UID1', 'ip:client', $authenticatedAccountId);
    }

    private function handler(?RegisteredNick $account, ?RescueCredentialPersistence $persistence = null, ?RegistrationEventPublisher $publisher = null, NicknameAuthenticationMode $mode = NicknameAuthenticationMode::ServiceCommand, ?RescueAttemptLimiter $limiter = null): VerifyNickHandler
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());
        $auth = $this->createStub(NicknameAuthenticationModeQuery::class);
        $auth->method('current')->willReturn($mode);

        return new VerifyNickHandler($repo, $this->codec, $this->temporary, $limiter ?? new InMemoryRescueAttemptLimiter(), $persistence ?? $this->createStub(RescueCredentialPersistence::class), $this->sessions, $clock, $auth, $publisher ?? $this->createStub(RegistrationEventPublisher::class));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
