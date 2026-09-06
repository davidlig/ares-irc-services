<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Register;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\RegistrationThrottle;
use App\NickServ\Application\Port\Out\RegistrationVerificationStore;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\UseCase\Register\RegisterNick;
use App\NickServ\Application\UseCase\Register\RegisterNickHandler;
use App\NickServ\Application\UseCase\Register\RegisterNickOutcome;
use App\NickServ\Application\UseCase\Register\RegisterNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RegisterNickHandler::class)]
#[CoversClass(RegisterNick::class)]
#[CoversClass(RegisterNickResult::class)]
final class RegisterNickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }

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
        $repository->expects(self::once())->method('save')->with(self::callback(fn (RegisteredNick $nick): bool => 'NewNick' === $nick->getNickname()
                && 'hashed-password' === $nick->getPasswordHash()
                && 'user@example.com' === $nick->getEmail()
                && 'es' === $nick->getLanguage()
                && $this->now == $nick->getRegisteredAt()
                && $this->now->modify('+1800 seconds') == $nick->getExpiresAt()));

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('hash')->with('plain-password')->willReturn('hashed-password');
        $tokenGenerator = $this->createMock(VerificationTokenGenerator::class);
        $tokenGenerator->expects(self::once())->method('generate')->willReturn('deterministic-token');
        $verificationStore = $this->createMock(RegistrationVerificationStore::class);
        $verificationStore->expects(self::once())->method('store')->with(
            'NewNick',
            'deterministic-token',
            self::callback(fn (DateTimeImmutable $expiresAt): bool => $this->now->modify('+1800 seconds') == $expiresAt),
        );
        $publisher = $this->createMock(RegistrationEventPublisher::class);
        $publisher->expects(self::once())->method('publish')->with(self::callback(
            static fn (NickPasswordHashAvailable $event): bool => null === $event->nickId
                && 'NewNick' === $event->nickname
                && 'hashed-password' === $event->passwordHash,
        ));
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::once())->method('sendVerification')->with(
            'NewNick',
            'user@example.com',
            'deterministic-token',
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
            publisher: $publisher,
            mail: $mail,
            throttle: $throttle,
            tokenTtl: 1800,
        )->handle($this->input());

        self::assertSame(RegisterNickOutcome::VerificationRequired, $result->outcome);
        self::assertSame('user@example.com', $result->email);
    }

    #[Test]
    public function mailFailureLeavesPendingStateAndDoesNotConsumeThrottle(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->method('findByEmail')->willReturn(null);
        $repository->method('findByNick')->willReturn(null);
        $repository->expects(self::once())->method('save');
        $verificationStore = $this->createMock(RegistrationVerificationStore::class);
        $verificationStore->expects(self::once())->method('store');
        $publisher = $this->createMock(RegistrationEventPublisher::class);
        $publisher->expects(self::once())->method('publish');
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::once())->method('sendVerification')->willThrowException(new RuntimeException('transport failed'));
        $throttle = $this->createMock(RegistrationThrottle::class);
        $throttle->method('remainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::never())->method('recordAttempt');

        $result = $this->handler(
            repository: $repository,
            passwordHasher: $this->passwordHasher(),
            tokenGenerator: $this->tokenGenerator(),
            verificationStore: $verificationStore,
            publisher: $publisher,
            mail: $mail,
            throttle: $throttle,
        )->handle($this->input());

        self::assertSame(RegisterNickOutcome::MailDeliveryFailed, $result->outcome);
    }

    private function input(
        string $nickname = 'NewNick',
        string $email = 'user@example.com',
    ): RegisterNick {
        return new RegisterNick($nickname, 'plain-password', $email, 'es', 'ip:client');
    }

    private function handler(
        ?RegisterNickRepository $repository = null,
        ?PasswordHasher $passwordHasher = null,
        ?VerificationTokenGenerator $tokenGenerator = null,
        ?RegistrationVerificationStore $verificationStore = null,
        ?RegistrationThrottle $throttle = null,
        ?RegistrationMailSender $mail = null,
        ?RegistrationEventPublisher $publisher = null,
        int $tokenTtl = 3600,
        string $guestPrefix = 'Guest-',
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

        return new RegisterNickHandler(
            $repository,
            $passwordHasher ?? $this->passwordHasher(),
            $tokenGenerator ?? $this->tokenGenerator(),
            $this->clock(),
            $verificationStore ?? $this->createStub(RegistrationVerificationStore::class),
            $throttle,
            $mail ?? $this->createStub(RegistrationMailSender::class),
            $publisher ?? $this->createStub(RegistrationEventPublisher::class),
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

    private function tokenGenerator(): VerificationTokenGenerator
    {
        $generator = $this->createStub(VerificationTokenGenerator::class);
        $generator->method('generate')->willReturn('deterministic-token');

        return $generator;
    }
}
