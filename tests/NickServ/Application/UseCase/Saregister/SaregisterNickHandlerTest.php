<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Saregister;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationMailSender;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\UseCase\Saregister\SaregisterNick;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickHandler;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickOutcome;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(SaregisterNickHandler::class)]
#[CoversClass(SaregisterNick::class)]
#[CoversClass(SaregisterNickResult::class)]
#[CoversClass(RegisteredNick::class)]
final class SaregisterNickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-22 09:00:00 UTC');
    }

    #[Test]
    public function tokenModeCreatesAnActiveAccountAndReturnsTheTokenOnce(): void
    {
        $repository = $this->expectsSingleSave();

        $result = $this->handler(repository: $repository, mode: RegistrationMode::Token)->handle(
            $this->input(email: null),
        );

        self::assertSame(SaregisterNickOutcome::RegisteredWithToken, $result->outcome);
        self::assertSame('NewNick', $result->nickname);
        self::assertSame(RegistrationMode::Token, $result->mode);
        self::assertSame('12345678-1234-4234-8234-123456789abc', $result->token);
        self::assertNull($result->email);
    }

    #[Test]
    public function tokenModeAcceptsAnOptionalEmailAndDoesNotSendMail(): void
    {
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::never())->method('sendVerification');

        $result = $this->handler(mail: $mail, mode: RegistrationMode::Token)->handle($this->input());

        self::assertSame(SaregisterNickOutcome::RegisteredWithToken, $result->outcome);
        self::assertSame('owner@example.com', $result->email);
    }

    #[Test]
    public function tokenModePersistsAnActiveAccountWithTheRescueCredential(): void
    {
        $saved = null;
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->method('findByEmail')->willReturn(null);
        $repository->method('findByNick')->willReturn(null);
        $repository->expects(self::once())->method('save')->willReturnCallback(
            static function (RegisteredNick $nick) use (&$saved): void {
                $saved = $nick;
            },
        );

        $this->handler(repository: $repository, mode: RegistrationMode::Token)->handle($this->input(email: null));

        self::assertInstanceOf(RegisteredNick::class, $saved);
        self::assertSame(NickStatus::Registered, $saved->getStatus());
        self::assertSame('NewNick', $saved->getNickname());
        self::assertSame('hashed-password', $saved->getPasswordHash());
        self::assertSame(str_repeat('a', 64), $saved->getRescueTokenHash());
        self::assertNull($saved->getExpiresAt());
        self::assertNull($saved->getEmail());
        self::assertSame('es', $saved->getLanguage());
        self::assertNotNull($saved->getRegisteredAt());
        self::assertSame($this->now->format('c'), $saved->getRegisteredAt()->format('c'));
    }

    #[Test]
    public function emailModeCreatesTheAccountAndSendsTheTokenByMail(): void
    {
        $mail = $this->createMock(RegistrationMailSender::class);
        $mail->expects(self::once())->method('sendVerification')->with(
            'NewNick',
            'owner@example.com',
            '12345678-1234-4234-8234-123456789abc',
            'es',
        );

        $result = $this->handler(mail: $mail, mode: RegistrationMode::Email)->handle($this->input());

        self::assertSame(SaregisterNickOutcome::Registered, $result->outcome);
        self::assertSame('owner@example.com', $result->email);
        self::assertNull($result->token);
    }

    #[Test]
    public function emailModeRequiresAnAddress(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::never())->method('save');
        $repository->expects(self::never())->method('findByNick');

        $result = $this->handler(repository: $repository, mode: RegistrationMode::Email)->handle(
            $this->input(email: null),
        );

        self::assertSame(SaregisterNickOutcome::InvalidEmail, $result->outcome);
    }

    #[Test]
    public function rejectsAMalformedAddressInEitherMode(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::never())->method('save');

        $result = $this->handler(repository: $repository, mode: RegistrationMode::Token)->handle(
            $this->input(email: 'not-an-address'),
        );

        self::assertSame(SaregisterNickOutcome::InvalidEmail, $result->outcome);
        self::assertSame('not-an-address', $result->email);
    }

    #[Test]
    public function rejectsAnAddressAlreadyOwnedByAnotherAccount(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->method('findByEmail')->willReturn($this->activeNick());
        $repository->expects(self::never())->method('save');
        $repository->expects(self::never())->method('findByNick');

        $result = $this->handler(repository: $repository)->handle($this->input());

        self::assertSame(SaregisterNickOutcome::EmailAlreadyUsed, $result->outcome);
        self::assertSame('owner@example.com', $result->email);
    }

    #[Test]
    public function reportsTheExistingAccountStateInsteadOfOverwritingIt(): void
    {
        self::assertSame(
            SaregisterNickOutcome::AlreadyPending,
            $this->handleExisting(RegisteredNick::createPending('NewNick', 'hash', null, 'en', $this->now, $this->now)),
        );

        $forbidden = RegisteredNick::createForbidden('NewNick', 'policy');
        self::assertSame(SaregisterNickOutcome::Forbidden, $this->handleExisting($forbidden));

        $pendingDeletion = $this->activeNick();
        $pendingDeletion->markPendingDeletion($this->now);
        self::assertSame(SaregisterNickOutcome::PendingDeletion, $this->handleExisting($pendingDeletion));

        self::assertSame(SaregisterNickOutcome::AlreadyRegistered, $this->handleExisting($this->activeNick()));
    }

    #[Test]
    public function reportsAMailDeliveryFailureWithoutLosingTheAccount(): void
    {
        $mail = $this->createStub(RegistrationMailSender::class);
        $mail->method('sendVerification')->willThrowException(new RuntimeException('smtp down'));

        $result = $this->handler(mail: $mail, mode: RegistrationMode::Email)->handle($this->input());

        self::assertSame(SaregisterNickOutcome::RegisteredWithoutMail, $result->outcome);
        self::assertSame('owner@example.com', $result->email);
    }

    private function handleExisting(RegisteredNick $existing): SaregisterNickOutcome
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->method('findByEmail')->willReturn(null);
        $repository->method('findByNick')->willReturn($existing);
        $repository->expects(self::never())->method('save');

        return $this->handler(repository: $repository, mode: RegistrationMode::Token)
            ->handle($this->input(email: null))
            ->outcome;
    }

    private function activeNick(): RegisteredNick
    {
        $nick = RegisteredNick::createActive('Existing', 'hash', null, 'en', $this->now);
        $nick->replaceRescueTokenHash(str_repeat('b', 64));

        return $nick;
    }

    private function expectsSingleSave(): RegisterNickRepository
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->method('findByEmail')->willReturn(null);
        $repository->method('findByNick')->willReturn(null);
        $repository->expects(self::once())->method('save');

        return $repository;
    }

    private function input(?string $email = 'owner@example.com'): SaregisterNick
    {
        return new SaregisterNick('NewNick', 'plain-password', $email, 'es');
    }

    private function handler(
        ?RegisterNickRepository $repository = null,
        ?RegistrationMailSender $mail = null,
        RegistrationMode $mode = RegistrationMode::Token,
    ): SaregisterNickHandler {
        if (null === $repository) {
            $repository = $this->createStub(RegisterNickRepository::class);
            $repository->method('findByEmail')->willReturn(null);
            $repository->method('findByNick')->willReturn(null);
        }

        $policy = $this->createStub(RegistrationModeQuery::class);
        $policy->method('current')->willReturn($mode);

        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturn('hashed-password');

        $codec = $this->createStub(RescueTokenCodec::class);
        $codec->method('generate')->willReturn('12345678-1234-4234-8234-123456789abc');
        $codec->method('hash')->willReturn(str_repeat('a', 64));

        return new SaregisterNickHandler(
            $repository,
            $hasher,
            $codec,
            $clock,
            $policy,
            $mail ?? $this->createStub(RegistrationMailSender::class),
        );
    }
}
