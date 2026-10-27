<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Recover;

use App\NickServ\Adapter\Out\InMemory\InMemoryRescueAttemptLimiter;
use App\NickServ\Adapter\Out\InMemory\InMemoryTemporaryRescueTokenStore;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RecoverEventPublisher;
use App\NickServ\Application\Port\Out\RecoveryMailSender;
use App\NickServ\Application\Port\Out\RecoveryPasswordGenerator;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\UseCase\Recover\RecoverNick;
use App\NickServ\Application\UseCase\Recover\RecoverNickHandler;
use App\NickServ\Application\UseCase\Recover\RecoverNickOutcome;
use App\NickServ\Application\UseCase\Recover\RecoverNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function hash;
use function strtoupper;

#[CoversClass(RecoverNickHandler::class)]
#[CoversClass(RecoverNickResult::class)]
#[CoversClass(RecoverNick::class)]
final class RecoverNickHandlerTest extends TestCase
{
    private const string TOKEN = '12345678-1234-4234-8234-123456789abc';

    private const string OLD = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private DateTimeImmutable $now;

    private RecoveryTokenRegistry $pending;

    private InMemoryTemporaryRescueTokenStore $optional;

    private InMemoryRescueAttemptLimiter $limiter;

    #[Test]
    public function tokenModeNoArgumentOnlyExplainsWithoutAccountLookup(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::never())->method('findByNick');
        self::assertSame(RecoverNickOutcome::Instructions, $this->handler(repository: $repo, mode: RegistrationMode::Token)->handle(new RecoverNick('User'))->outcome);
    }

    #[Test]
    public function emailRequestKeepsConfirmedCredentialAndMailsNewProvisionalToken(): void
    {
        $account = $this->account();
        $mail = $this->createMock(RecoveryMailSender::class);
        $sent = '';
        $mail->expects(self::once())->method('sendRecovery')->willReturnCallback(static function (string $nick, string $email, string $token, string $locale) use (&$sent): void {
            $sent = $token;
            self::assertSame('user@example.com', $email);
        });
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::never())->method('rotateAfterRecovery');
        $result = $this->handler($account, credentials: $persistence, mail: $mail)->handle(new RecoverNick('User'));
        self::assertSame(RecoverNickOutcome::TokenSent, $result->outcome);
        self::assertTrue($this->pending->matches('User', $sent, $this->now));
        self::assertSame(hash('sha256', self::OLD), $account->getRescueTokenHash());
        self::assertNull($result->rescueToken);
    }

    #[Test]
    public function invalidProvidedTokenCountsAttemptAndRequestsEmailWithoutRevokingOld(): void
    {
        $result = $this->handler($this->account())->handle(new RecoverNick('User', 'wrong', clientKey: 'ip:raw'));
        self::assertSame(RecoverNickOutcome::TokenSent, $result->outcome);
        for ($i = 0; $i < 4; ++$i) {
            $this->limiter->recordFailure(42, 'ip:raw', $this->now);
        }
        self::assertGreaterThan(0, $this->limiter->remainingCooldownSeconds(42, 'ip:raw', $this->now));
    }

    #[Test]
    public function emailedTokenPromotesSameHashIncludingLegacyNullCredential(): void
    {
        foreach ([null, hash('sha256', self::OLD)] as $old) {
            $this->pending->store('User', self::TOKEN, $this->now->modify('+1 hour'));
            $this->optional->replace(42, hash('sha256', 'stale-challenge'), $this->now->modify('+1 hour'));
            $credentials = $this->createMock(RescueCredentialPersistence::class);
            $credentials->expects(self::once())->method('rotateAfterRecovery')->with(42, $old, 'new-password-hash', hash('sha256', self::TOKEN), $this->now)->willReturn(true);
            $events = $this->createMock(RecoverEventPublisher::class);
            $events->expects(self::exactly(2))->method('publish');
            $result = $this->handler($this->account($old), credentials: $credentials, events: $events)->handle(new RecoverNick('User', strtoupper(self::TOKEN)));
            self::assertTrue($result->tokenPromoted);
            self::assertSame(RecoverNickOutcome::PasswordReset, $result->outcome);
            self::assertFalse($this->pending->matches('User', self::TOKEN, $this->now));
            self::assertFalse($this->optional->matches(42, hash('sha256', 'stale-challenge'), $this->now));
            self::assertSame('new-password', $result->temporaryPassword);
            self::assertSame(strtoupper(self::TOKEN), $result->rescueToken);
        }
    }

    #[Test]
    public function alreadyEmailedProofRemainsValidAfterSwitchToTokenMode(): void
    {
        $this->pending->store('User', self::TOKEN, $this->now->modify('+1 hour'));
        $mail = $this->createMock(RecoveryMailSender::class);
        $mail->expects(self::never())->method('sendRecovery');
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::once())->method('rotateAfterRecovery')->with(42, hash('sha256', self::OLD), 'new-password-hash', hash('sha256', self::TOKEN), $this->now)->willReturn(true);
        $result = $this->handler($this->account(), credentials: $credentials, mail: $mail, mode: RegistrationMode::Token)->handle(new RecoverNick('User', self::TOKEN));
        self::assertSame(RecoverNickOutcome::PasswordReset, $result->outcome);
        self::assertTrue($result->tokenPromoted);
        self::assertSame(self::TOKEN, $result->rescueToken);
        self::assertFalse($this->pending->matches('User', self::TOKEN, $this->now));
    }

    #[Test]
    public function confirmedProofPreservesOldHashAndReturnsOnlyOptionalMemoryReplacement(): void
    {
        $old = hash('sha256', self::OLD);
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::once())->method('rotateAfterRecovery')->with(42, $old, 'new-password-hash', $old, $this->now)->willReturn(true);
        $result = $this->handler($this->account(), credentials: $credentials)->handle(new RecoverNick('User', self::OLD));
        self::assertSame(RecoverNickOutcome::PasswordReset, $result->outcome);
        self::assertFalse($result->tokenPromoted);
        self::assertNotNull($result->rescueToken);
        self::assertTrue($this->optional->matches(42, hash('sha256', $result->rescueToken), $this->now));
        self::assertFalse($this->optional->matches(42, hash('sha256', $result->rescueToken), $this->now->modify('+3600 seconds')));
    }

    #[Test]
    public function failedCasPreservesProvisionalProofAndPublishesNothing(): void
    {
        $this->pending->store('User', self::TOKEN, $this->now->modify('+1 hour'));
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::once())->method('rotateAfterRecovery')->willReturn(false);
        $events = $this->createMock(RecoverEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $result = $this->handler($this->account(), credentials: $credentials, events: $events)->handle(new RecoverNick('User', self::TOKEN));
        self::assertSame(RecoverNickOutcome::InvalidToken, $result->outcome);
        self::assertNull($result->temporaryPassword);
        self::assertTrue($this->pending->matches('User', self::TOKEN, $this->now));
    }

    #[Test]
    public function mailIntervalAndSharedLockoutRejectFurtherRequests(): void
    {
        $this->pending->recordRecover('User', $this->now->modify('-60 seconds'));
        $result = $this->handler($this->account())->handle(new RecoverNick('User'));
        self::assertSame(RecoverNickOutcome::Throttled, $result->outcome);
        self::assertSame(540, $result->retryAfterSeconds);
        for ($i = 0; $i < 5; ++$i) {
            $this->limiter->recordFailure(42, 'client', $this->now);
        }
        self::assertSame(RecoverNickOutcome::Throttled, $this->handler($this->account())->handle(new RecoverNick('User', self::OLD, clientKey: 'client'))->outcome);
    }

    #[Test]
    public function invalidOrMissingEmailCannotRequestRecovery(): void
    {
        foreach ([null, '', 'not-email'] as $email) {
            self::assertSame(RecoverNickOutcome::NoEmail, $this->handler($this->account(email: $email))->handle(new RecoverNick('User'))->outcome);
        }
    }

    #[Test]
    public function mailFailureReturnsHonestErrorAndNoConfirmedMutation(): void
    {
        $mail = $this->createStub(RecoveryMailSender::class);
        $mail->method('sendRecovery')->willThrowException(new RuntimeException('smtp failed'));
        self::assertSame(RecoverNickOutcome::MailDeliveryFailed, $this->handler($this->account(), mail: $mail)->handle(new RecoverNick('User'))->outcome);
        self::assertNotNull($this->pending->getLastRecoverAt('User'));
    }

    #[Test]
    public function expiredEmailProofIsNotPromotedAndRequestsFreshMail(): void
    {
        $this->pending->store('User', self::TOKEN, $this->now);
        self::assertSame(RecoverNickOutcome::TokenSent, $this->handler($this->account())->handle(new RecoverNick('User', self::TOKEN))->outcome);
    }

    #[Test]
    public function optionalStoreCapacityDoesNotTurnSuccessfulPasswordResetIntoFailure(): void
    {
        $this->optional = new InMemoryTemporaryRescueTokenStore(1);
        $this->optional->replace(99, hash('sha256', self::TOKEN), $this->now->modify('+1 hour'));
        $result = $this->handler($this->account())->handle(new RecoverNick('User', self::OLD));
        self::assertSame(RecoverNickOutcome::PasswordReset, $result->outcome);
        self::assertTrue($result->replacementUnavailable);
        self::assertNull($result->rescueToken);
        self::assertSame('new-password', $result->temporaryPassword);
    }

    #[Test]
    public function unknownAndRestrictedAccountsRemainRejected(): void
    {
        self::assertSame(RecoverNickOutcome::NotRegistered, $this->handler()->handle(new RecoverNick('Missing'))->outcome);
        foreach (['isPending' => RecoverNickOutcome::Pending, 'isSuspended' => RecoverNickOutcome::Suspended, 'isForbidden' => RecoverNickOutcome::Forbidden, 'isPendingDeletion' => RecoverNickOutcome::PendingDeletion] as $method => $expected) {
            $account = $this->createStub(RegisteredNick::class);
            $account->method($method)->willReturn(true);
            self::assertSame($expected, $this->handler($account)->handle(new RecoverNick('User'))->outcome);
        }
    }

    #[Test]
    public function emailCapacityFailureReturnsUnavailableWithoutSending(): void
    {
        $this->pending = new RecoveryTokenRegistry(1);
        $this->pending->store('Other', self::TOKEN, $this->now->modify('+1 hour'));
        $mail = $this->createMock(RecoveryMailSender::class);
        $mail->expects(self::never())->method('sendRecovery');
        self::assertSame(RecoverNickOutcome::TemporarilyUnavailable, $this->handler($this->account(), mail: $mail)->handle(new RecoverNick('User'))->outcome);
    }

    #[Test]
    public function wrongTokenInTokenModeDoesNotSendMailAndDistinguishesMissingCredential(): void
    {
        $mail = $this->createMock(RecoveryMailSender::class);
        $mail->expects(self::never())->method('sendRecovery');
        self::assertSame(RecoverNickOutcome::InvalidToken, $this->handler($this->account(), mail: $mail, mode: RegistrationMode::Token)->handle(new RecoverNick('User', 'bad'))->outcome);
        self::assertSame(RecoverNickOutcome::CredentialUnavailable, $this->handler($this->account(null), mail: $mail, mode: RegistrationMode::Token)->handle(new RecoverNick('User', 'bad'))->outcome);
    }

    #[Test]
    public function confirmedTokenProofWorksWithoutEmailInBothGlobalModes(): void
    {
        foreach ([RegistrationMode::Email, RegistrationMode::Token] as $mode) {
            self::assertSame(RecoverNickOutcome::PasswordReset, $this->handler($this->account(email: null), mode: $mode)->handle(new RecoverNick('User', self::OLD))->outcome);
        }
    }

    #[Test]
    public function failedConfirmedProofCasPreservesPreviousOptionalChallenge(): void
    {
        $oldChallenge = hash('sha256', self::TOKEN);
        $this->optional->replace(42, $oldChallenge, $this->now->modify('+1 hour'));
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::once())->method('rotateAfterRecovery')->willReturn(false);
        $events = $this->createMock(RecoverEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $result = $this->handler($this->account(), credentials: $credentials, events: $events)->handle(new RecoverNick('User', self::OLD));
        self::assertSame(RecoverNickOutcome::InvalidToken, $result->outcome);
        self::assertNull($result->rescueToken);
        self::assertTrue($this->optional->matches(42, $oldChallenge, $this->now));
    }

    #[Test]
    public function fifthInvalidAttemptStopsBeforeAnotherMailRequest(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            $this->limiter->recordFailure(42, 'client', $this->now);
        }
        $mail = $this->createMock(RecoveryMailSender::class);
        $mail->expects(self::never())->method('sendRecovery');
        self::assertSame(RecoverNickOutcome::Throttled, $this->handler($this->account(), mail: $mail)->handle(new RecoverNick('User', 'bad', clientKey: 'client'))->outcome);
    }

    #[Test]
    public function invalidGeneratedReplacementCannotCommitPasswordChange(): void
    {
        $codec = $this->createStub(RescueTokenCodec::class);
        $codec->method('hash')->willReturnCallback(static fn (string $token): ?string => self::OLD === $token ? hash('sha256', self::OLD) : null);
        $codec->method('generate')->willReturn('invalid-generated');
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::never())->method('rotateAfterRecovery');
        $this->expectException(LogicException::class);
        $this->handler($this->account(), credentials: $credentials, codec: $codec)->handle(new RecoverNick('User', self::OLD));
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-22 UTC');
        $this->pending = new RecoveryTokenRegistry();
        $this->optional = new InMemoryTemporaryRescueTokenStore();
        $this->limiter = new InMemoryRescueAttemptLimiter();
    }

    private function account(?string $hash = 'default', ?string $email = 'user@example.com'): RegisteredNick
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $account->method('getRescueTokenHash')->willReturn('default' === $hash ? hash('sha256', self::OLD) : $hash);
        $account->method('getEmail')->willReturn($email);
        $account->method('getLanguage')->willReturn('en');

        return $account;
    }

    private function handler(?RegisteredNick $account = null, ?RegisteredNickRepositoryInterface $repository = null, ?RescueCredentialPersistence $credentials = null, ?RecoveryMailSender $mail = null, ?RecoverEventPublisher $events = null, RegistrationMode $mode = RegistrationMode::Email, ?RescueTokenCodec $codec = null): RecoverNickHandler
    {
        if (null === $repository) {
            $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
            $repository->method('findByNick')->willReturn($account);
        }
        if (null === $credentials) {
            $credentials = $this->createStub(RescueCredentialPersistence::class);
            $credentials->method('rotateAfterRecovery')->willReturn(true);
        }
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);
        $passwords = $this->createStub(RecoveryPasswordGenerator::class);
        $passwords->method('generate')->willReturn('new-password');
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturn('new-password-hash');
        $policy = $this->createStub(RegistrationModeQuery::class);
        $policy->method('current')->willReturn($mode);

        return new RecoverNickHandler($repository, $hasher, $passwords, $codec ?? new UuidRescueTokenCodec(), $credentials, $events ?? $this->createStub(RecoverEventPublisher::class), $clock, $policy, $this->pending, $mail ?? $this->createStub(RecoveryMailSender::class), $this->limiter, $this->optional);
    }
}
