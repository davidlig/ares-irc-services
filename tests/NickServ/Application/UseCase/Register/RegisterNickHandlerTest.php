<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Register;

use App\NickServ\Adapter\Out\InMemory\InMemoryRegistrationThrottle;
use App\NickServ\Adapter\Out\InMemory\RegisterThrottleRegistry;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailDomainPolicy;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RegistrationThrottle;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use App\NickServ\Application\UseCase\Register\RegisterNick;
use App\NickServ\Application\UseCase\Register\RegisterNickHandler;
use App\NickServ\Application\UseCase\Register\RegisterNickOutcome;
use App\NickServ\Application\UseCase\Register\RegisterNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function str_repeat;

#[CoversClass(RegisterNickHandler::class)]
#[CoversClass(RegisterNick::class)]
#[CoversClass(RegisterNickResult::class)]
final class RegisterNickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    #[Test]
    public function rejectsThrottledClientBeforeOtherValidation(): void
    {
        $throttle = $this->createMock(RegistrationThrottle::class);
        $throttle->expects(self::once())->method('remainingCooldownSeconds')
            ->with('ip:client', 300, $this->now)
            ->willReturn(61);
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::never())->method('findByEmail');

        $result = $this->handler(repository: $repository, throttle: $throttle)->handle($this->input());

        self::assertSame(RegisterNickOutcome::Throttled, $result->outcome);
        self::assertSame(61, $result->retryAfterSeconds);
    }

    #[Test]
    public function rejectsConfiguredGuestPrefixBeforeLookups(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::never())->method('findByEmail');

        $result = $this->handler(repository: $repository, guestPrefix: 'Renamed-')->handle(
            $this->input(nickname: 'Renamed-ABC'),
        );

        self::assertSame(RegisterNickOutcome::GuestPrefixForbidden, $result->outcome);
        self::assertSame('Renamed-', $result->guestPrefix);
    }

    #[Test]
    public function rejectsInvalidEmailWithoutRepositoryLookup(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::never())->method('findByEmail');

        $result = $this->handler(repository: $repository)->handle($this->input(email: 'invalid'));

        self::assertSame(RegisterNickOutcome::InvalidEmail, $result->outcome);
    }

    #[Test]
    public function checksEmailOwnershipBeforeNicknameOwnership(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::once())->method('findByEmail')->with('used@example.com')->willReturn(
            $this->createStub(RegisteredNick::class),
        );
        $repository->expects(self::never())->method('findByNick');

        $result = $this->handler(repository: $repository)->handle($this->input(email: 'used@example.com'));

        self::assertSame(RegisterNickOutcome::EmailAlreadyUsed, $result->outcome);
        self::assertSame('used@example.com', $result->email);
    }

    #[Test]
    public function mapsEveryExistingNicknameStatusToBusinessMeaning(): void
    {
        $cases = [
            NickStatus::Pending->value => RegisterNickOutcome::AlreadyPending,
            NickStatus::Forbidden->value => RegisterNickOutcome::Forbidden,
            NickStatus::PendingDeletion->value => RegisterNickOutcome::PendingDeletion,
            NickStatus::Registered->value => RegisterNickOutcome::AlreadyRegistered,
            NickStatus::Suspended->value => RegisterNickOutcome::AlreadyRegistered,
        ];

        foreach ($cases as $statusValue => $expected) {
            $existing = $this->createStub(RegisteredNick::class);
            $existing->method('getStatus')->willReturn(NickStatus::from($statusValue));
            $repository = $this->createStub(RegisterNickRepository::class);
            $repository->method('findByEmail')->willReturn(null);
            $repository->method('findByNick')->willReturn($existing);

            $result = $this->handler(repository: $repository)->handle($this->input());

            self::assertSame($expected, $result->outcome);
            self::assertSame('NewNick', $result->nickname);
        }
    }

    #[Test]
    public function createsPendingRegistrationWithDeterministicExternalFacts(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::once())->method('findByEmail')->with('user@example.com')->willReturn(null);
        $repository->expects(self::once())->method('findByNick')->with('NewNick')->willReturn(null);
        $repository->expects(self::once())->method('save')->willReturnCallback(static fn (RegisteredNick $nick) => new ReflectionProperty($nick, 'id')->setValue($nick, 42))->with(self::callback(fn (RegisteredNick $nick): bool => 'NewNick' === $nick->getNickname()
                && null === $nick->getRescueTokenHash()
                && 'hashed-password' === $nick->getPasswordHash()
                && 'user@example.com' === $nick->getEmail()
                && 'es' === $nick->getLanguage()
                && $this->now == $nick->getRegisteredAt()
                && $this->now->modify('+1800 seconds') == $nick->getExpiresAt()));

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('hash')->with('plain-password')->willReturn('hashed-password');
        $tokenGenerator = $this->createMock(RescueTokenCodec::class);
        $tokenGenerator->expects(self::once())->method('generate')->willReturn('12345678-1234-4234-8234-123456789abc');
        $tokenGenerator->method('hash')->willReturn(str_repeat('a', 64));
        $verificationStore = $this->createMock(TemporaryRescueTokenStore::class);
        $verificationStore->expects(self::once())->method('replace');
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::once())->method('sendVerification')->with(
            'NewNick',
            'user@example.com',
            '12345678-1234-4234-8234-123456789abc',
            'es',
        );
        $throttle = $this->createMock(RegistrationThrottle::class);
        $throttle->expects(self::once())->method('remainingCooldownSeconds')->with('ip:client', 300, $this->now)->willReturn(0);
        $throttle->expects(self::once())->method('recordAttempt')->with('ip:client', $this->now);

        $result = $this->handler(
            repository: $repository,
            passwordHasher: $hasher,
            tokenGenerator: $tokenGenerator,
            verificationStore: $verificationStore,
            mail: $mail,
            throttle: $throttle,
            tokenTtl: 1800,
        )->handle($this->input());

        self::assertSame(RegisterNickOutcome::VerificationRequired, $result->outcome);
        self::assertSame('user@example.com', $result->email);
    }

    #[Test]
    public function mailFailureLeavesPendingStateAndRecordsIpCooldown(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->method('findByEmail')->willReturn(null);
        $repository->method('findByNick')->willReturn(null);
        $repository->expects(self::once())->method('save')->willReturnCallback(static fn (RegisteredNick $nick) => new ReflectionProperty($nick, 'id')->setValue($nick, 42));
        $verificationStore = $this->createMock(TemporaryRescueTokenStore::class);
        $verificationStore->expects(self::once())->method('replace');
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::once())->method('sendVerification')->willThrowException(new RuntimeException('transport failed'));
        $throttle = $this->createMock(RegistrationThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::once())->method('recordAttempt')->with('ip:client', $this->now);

        $result = $this->handler(
            repository: $repository,
            passwordHasher: $this->passwordHasher(),
            tokenGenerator: $this->tokenGenerator(),
            verificationStore: $verificationStore,
            mail: $mail,
            throttle: $throttle,
        )->handle($this->input());

        self::assertSame(RegisterNickOutcome::MailDeliveryFailed, $result->outcome);
    }

    #[Test]
    public function tokenModeStoresOnlyTemporaryHashAndAllowsAbsentEmail(): void
    {
        $repo = $this->createMock(RegisterNickRepository::class);
        $repo->expects(self::never())->method('findByEmail');
        $repo->method('findByNick')->willReturn(null);
        $repo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredNick $nick): void {
            self::assertNull($nick->getRescueTokenHash());
            self::assertNull($nick->getEmail());
            new ReflectionProperty($nick, 'id')->setValue($nick, 42);
        });
        $store = $this->createMock(TemporaryRescueTokenStore::class);
        $store->expects(self::once())->method('prune')->with($this->now);
        $store->expects(self::once())->method('replace')->with(42, str_repeat('a', 64), $this->now->modify('+3600 seconds'));
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::never())->method('sendVerification');
        $result = $this->handler(repository: $repo, verificationStore: $store, mail: $mail, mode: RegistrationMode::Token)->handle($this->input(email: null));
        self::assertSame(RegisterNickOutcome::TokenVerificationRequired, $result->outcome);
        self::assertSame('12345678-1234-4234-8234-123456789abc', $result->token);
    }

    #[Test]
    public function storeOverflowStillLimitsRegistrationsByIpAcrossNicknames(): void
    {
        foreach ([RegistrationMode::Email, RegistrationMode::Token] as $mode) {
            $repo = $this->createMock(RegisterNickRepository::class);
            $repo->expects(self::exactly(2))->method('save')->willReturnCallback(static fn (RegisteredNick $nick) => new ReflectionProperty($nick, 'id')->setValue($nick, 42));
            $store = $this->createStub(TemporaryRescueTokenStore::class);
            $store->method('replace')->willThrowException(new OverflowException());
            $throttle = new InMemoryRegistrationThrottle(new RegisterThrottleRegistry());
            $handler = $this->handler(repository: $repo, verificationStore: $store, throttle: $throttle, mode: $mode);
            $expected = RegistrationMode::Token === $mode ? RegisterNickOutcome::TemporarilyUnavailable : RegisterNickOutcome::MailDeliveryFailed;
            self::assertSame($expected, $handler->handle(new RegisterNick('First', 'password', 'a@example.com', 'en', 'ip:A'))->outcome);
            $blocked = $handler->handle(new RegisterNick('Second', 'password', 'b@example.com', 'en', 'ip:A'));
            self::assertSame(RegisterNickOutcome::Throttled, $blocked->outcome);
            self::assertSame(300, $blocked->retryAfterSeconds);
            self::assertSame($expected, $handler->handle(new RegisterNick('Third', 'password', 'c@example.com', 'en', 'ip:B'))->outcome);
        }
    }

    #[Test]
    public function emailModeRequiresEmail(): void
    {
        self::assertSame(RegisterNickOutcome::InvalidEmail, $this->handler()->handle($this->input(email: null))->outcome);
    }

    #[Test]
    public function fullTemporaryStoreReportsUnavailableWithoutClaimingDelivery(): void
    {
        $repo = $this->createStub(RegisterNickRepository::class);
        $repo->method('save')->willReturnCallback(static fn (RegisteredNick $nick) => new ReflectionProperty($nick, 'id')->setValue($nick, 42));
        $store = $this->createStub(TemporaryRescueTokenStore::class);
        $store->method('replace')->willThrowException(new OverflowException());
        $result = $this->handler(repository: $repo, verificationStore: $store, mode: RegistrationMode::Token)->handle($this->input(email: null));
        self::assertSame(RegisterNickOutcome::TemporarilyUnavailable, $result->outcome);
        self::assertNull($result->token);
    }

    #[Test]
    public function tokenPendingRegistrationNeverReissuesOrExtendsChallenge(): void
    {
        $pending = RegisteredNick::createPending('NewNick', 'hash', null, 'en', $this->now->modify('+1 hour'), $this->now);
        $expiry = $pending->getExpiresAt();
        $repo = $this->createMock(RegisterNickRepository::class);
        $repo->method('findByNick')->willReturn($pending);
        $repo->expects(self::never())->method('save');
        $temporary = $this->createMock(TemporaryRescueTokenStore::class);
        $temporary->expects(self::never())->method('replace');
        $result = $this->handler(repository: $repo, verificationStore: $temporary, mode: RegistrationMode::Token)->handle($this->input(email: null));
        self::assertSame(RegisterNickOutcome::AlreadyPending, $result->outcome);
        self::assertSame($expiry, $pending->getExpiresAt());
        self::assertNull($result->token);
        self::assertNull($pending->getRescueTokenHash());
    }

    #[Test]
    public function emailRegistrationKeepsOnlyTemporaryHashBeforeVerify(): void
    {
        $repo = $this->createMock(RegisterNickRepository::class);
        $repo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredNick $nick): void {
            self::assertTrue($nick->isPending());
            self::assertNull($nick->getRescueTokenHash());
            new ReflectionProperty($nick, 'id')->setValue($nick, 42);
        });
        $store = $this->createMock(TemporaryRescueTokenStore::class);
        $store->expects(self::once())->method('replace')->with(42, str_repeat('a', 64), $this->now->modify('+3600 seconds'));
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::once())->method('sendVerification')->with('NewNick', 'user@example.com', '12345678-1234-4234-8234-123456789abc', 'es');
        $result = $this->handler(repository: $repo, verificationStore: $store, mail: $mail)->handle($this->input());
        self::assertSame(RegisterNickOutcome::VerificationRequired, $result->outcome);
        self::assertNull($result->token);
    }

    #[Test]
    public function rejectsBlockedDomainsInBothModesBeforeAnyRegistrationSideEffect(): void
    {
        foreach ([RegistrationMode::Email, RegistrationMode::Token] as $mode) {
            $policy = $this->createMock(EmailDomainPolicy::class);
            $policy->expects(self::once())->method('isBlocked')->with('user@BLOCKED.ORG')->willReturn(true);
            $repository = $this->createMock(RegisterNickRepository::class);
            $repository->expects(self::never())->method('findByEmail');
            $repository->expects(self::never())->method('findByNick');
            $repository->expects(self::never())->method('save');
            $hasher = $this->createMock(PasswordHasher::class);
            $hasher->expects(self::never())->method('hash');
            $tokens = $this->createMock(RescueTokenCodec::class);
            $tokens->expects(self::never())->method('generate');
            $mail = $this->createMock(RegistrationMailSender::class);
            $mail->expects(self::never())->method('sendVerification');
            $store = $this->createMock(TemporaryRescueTokenStore::class);
            $store->expects(self::never())->method('replace');
            $store->expects(self::never())->method('prune');
            $throttle = $this->createMock(RegistrationThrottle::class);
            $throttle->method('remainingCooldownSeconds')->willReturn(0);
            $throttle->expects(self::never())->method('recordAttempt');
            $result = $this->handler(repository: $repository, passwordHasher: $hasher, tokenGenerator: $tokens, verificationStore: $store, mail: $mail, throttle: $throttle, mode: $mode, emailPolicy: $policy)->handle($this->input(email: 'user@BLOCKED.ORG'));
            self::assertSame(RegisterNickOutcome::BlockedEmailDomain, $result->outcome);
        }
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }

    private function input(
        string $nickname = 'NewNick',
        ?string $email = 'user@example.com',
    ): RegisterNick {
        return new RegisterNick($nickname, 'plain-password', $email, 'es', 'ip:client');
    }

    private function handler(
        ?RegisterNickRepository $repository = null,
        ?PasswordHasher $passwordHasher = null,
        ?RescueTokenCodec $tokenGenerator = null,
        ?TemporaryRescueTokenStore $verificationStore = null,
        ?RegistrationThrottle $throttle = null,
        ?RegistrationMailSender $mail = null,
        int $tokenTtl = 3600,
        string $guestPrefix = 'Guest-',
        RegistrationMode $mode = RegistrationMode::Email,
        ?EmailDomainPolicy $emailPolicy = null,
    ): RegisterNickHandler {
        if (null === $repository) {
            $repository = $this->createStub(RegisterNickRepository::class);
            $repository->method('findByEmail')->willReturn(null);
            $repository->method('findByNick')->willReturn(null);
        }

        if (null === $throttle) {
            $throttle = $this->createStub(RegistrationThrottle::class);
            $throttle->method('remainingCooldownSeconds')->willReturn(0);
        }

        $policy = $this->createStub(RegistrationModeQuery::class);
        $policy->method('current')->willReturn($mode);

        return new RegisterNickHandler(
            $emailPolicy ?? $this->createStub(EmailDomainPolicy::class),
            $repository,
            $passwordHasher ?? $this->passwordHasher(),
            $tokenGenerator ?? $this->tokenGenerator(),
            $this->clock(),
            $verificationStore ?? $this->createStub(TemporaryRescueTokenStore::class),
            $policy,
            $throttle,
            $mail ?? $this->createStub(RegistrationMailSender::class),
            300,
            $tokenTtl,
            $guestPrefix,
        );
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        return $clock;
    }

    private function passwordHasher(): PasswordHasher
    {
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturn('hashed-password');

        return $hasher;
    }

    private function tokenGenerator(): RescueTokenCodec
    {
        $generator = $this->createStub(RescueTokenCodec::class);
        $generator->method('generate')->willReturn('12345678-1234-4234-8234-123456789abc');
        $generator->method('hash')->willReturn(str_repeat('a', 64));

        return $generator;
    }
}
