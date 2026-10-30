<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Saregister;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\EmailDomainPolicy;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\NicknamePolicyInterface;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\SaregisterMailSender;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\UseCase\Saregister\SaregisterNick;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickHandler;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickOutcome;
use App\NickServ\Application\UseCase\Saregister\SaregisterNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function hash;

#[CoversClass(SaregisterNickHandler::class)]
#[CoversClass(SaregisterNick::class)]
#[CoversClass(SaregisterNickResult::class)]
final class SaregisterNickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    #[Test]
    public function rejectsInvalidNicknameBeforeAnyOperation(): void
    {
        $policy = $this->createMock(NicknamePolicyInterface::class);
        $policy->expects(self::once())->method('isValid')->with('#not-a-nick')->willReturn(false);

        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::never())->method('findByNick');
        $repository->expects(self::never())->method('save');

        $events = $this->createMock(RegistrationEventPublisher::class);
        $events->expects(self::never())->method('publish');

        $handler = $this->handler(repository: $repository, eventPublisher: $events, nicknamePolicy: $policy);
        $result = $handler->handle($this->command(nickname: '#not-a-nick'));

        self::assertSame(SaregisterNickOutcome::InvalidNickname, $result->outcome);
        self::assertSame('#not-a-nick', $result->nickname);
    }

    #[Test]
    public function rejectsGuestPrefixImmediately(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::never())->method('findByNick');

        $handler = $this->handler(repository: $repository);
        $result = $handler->handle($this->command(nickname: 'Guest-12345'));

        self::assertSame(SaregisterNickOutcome::GuestPrefixForbidden, $result->outcome);
        self::assertSame('Guest-', $result->guestPrefix);
    }

    #[Test]
    public function rejectsMissingOrMalformedEmailInEmailMode(): void
    {
        $handler = $this->handler(mode: RegistrationMode::Email);

        $resultNoEmail = $handler->handle($this->command(email: null));
        self::assertSame(SaregisterNickOutcome::InvalidEmail, $resultNoEmail->outcome);

        $resultMalformed = $handler->handle($this->command(email: 'not-an-email'));
        self::assertSame(SaregisterNickOutcome::InvalidEmail, $resultMalformed->outcome);
    }

    #[Test]
    public function rejectsBlockedEmailDomainInEmailMode(): void
    {
        $policy = $this->createMock(EmailDomainPolicy::class);
        $policy->expects(self::once())->method('isBlocked')->with('user@blocked.com')->willReturn(true);

        $handler = $this->handler(mode: RegistrationMode::Email, emailDomainPolicy: $policy);
        $result = $handler->handle($this->command(email: 'user@blocked.com'));

        self::assertSame(SaregisterNickOutcome::BlockedEmailDomain, $result->outcome);
    }

    #[Test]
    public function rejectsDuplicateEmailInEmailMode(): void
    {
        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::once())->method('findByEmail')->with('used@example.com')
            ->willReturn($this->createStub(RegisteredNick::class));

        $handler = $this->handler(mode: RegistrationMode::Email, repository: $repository);
        $result = $handler->handle($this->command(email: 'used@example.com'));

        self::assertSame(SaregisterNickOutcome::EmailAlreadyUsed, $result->outcome);
        self::assertSame('used@example.com', $result->email);
    }

    #[Test]
    public function validatesOptionalEmailInTokenMode(): void
    {
        $handler = $this->handler(mode: RegistrationMode::Token);

        $resultMalformed = $handler->handle($this->command(email: 'invalid-email'));
        self::assertSame(SaregisterNickOutcome::InvalidEmail, $resultMalformed->outcome);

        $policy = $this->createMock(EmailDomainPolicy::class);
        $policy->expects(self::once())->method('isBlocked')->with('user@spam.com')->willReturn(true);

        $handlerPolicy = $this->handler(mode: RegistrationMode::Token, emailDomainPolicy: $policy);
        $resultBlocked = $handlerPolicy->handle($this->command(email: 'user@spam.com'));
        self::assertSame(SaregisterNickOutcome::BlockedEmailDomain, $resultBlocked->outcome);

        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::once())->method('findByEmail')->with('duplicate@example.com')
            ->willReturn($this->createStub(RegisteredNick::class));

        $handlerRepo = $this->handler(mode: RegistrationMode::Token, repository: $repository);
        $resultDuplicate = $handlerRepo->handle($this->command(email: 'duplicate@example.com'));
        self::assertSame(SaregisterNickOutcome::EmailAlreadyUsed, $resultDuplicate->outcome);
    }

    #[Test]
    public function rejectsExistingNicknamesAccordingToStatus(): void
    {
        $statuses = [
            [NickStatus::Pending, SaregisterNickOutcome::AlreadyPending],
            [NickStatus::Forbidden, SaregisterNickOutcome::Forbidden],
            [NickStatus::PendingDeletion, SaregisterNickOutcome::PendingDeletion],
            [NickStatus::Registered, SaregisterNickOutcome::AlreadyRegistered],
        ];

        foreach ($statuses as [$status, $expectedOutcome]) {
            $existing = $this->createStub(RegisteredNick::class);
            $existing->method('getStatus')->willReturn($status);

            $repository = $this->createMock(RegisterNickRepository::class);
            $repository->expects(self::once())->method('findByNick')->with('TargetNick')->willReturn($existing);

            $handler = $this->handler(repository: $repository);
            $result = $handler->handle($this->command(nickname: 'TargetNick'));

            self::assertSame($expectedOutcome, $result->outcome);
            self::assertSame('TargetNick', $result->nickname);
        }
    }

    #[Test]
    public function registersImmediatelyInTokenModeAndReturnsToken(): void
    {
        $tokenHash = hash('sha256', 'uuid-token-v4');

        $repository = $this->createMock(RegisterNickRepository::class);
        $repository->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredNick $nick) use ($tokenHash): void {
            self::assertSame('NewNick', $nick->getNickname());
            self::assertTrue($nick->isRegistered());
            self::assertSame('hashed_password', $nick->getPasswordHash());
            self::assertSame($tokenHash, $nick->getRescueTokenHash());
            self::assertSame('optional@example.com', $nick->getEmail());
            self::assertSame('es', $nick->getLanguage());
            new ReflectionProperty($nick, 'id')->setValue($nick, 42);
        });

        $eventPublisher = $this->createMock(RegistrationEventPublisher::class);
        $eventPublisher->expects(self::once())->method('publish')->with(self::callback(function (object $event): bool {
            return $event instanceof NickPasswordHashAvailable
                && 'NewNick' === $event->nickname
                && 'hashed_password' === $event->passwordHash;
        }));

        $historyRepo = $this->createMock(NickHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())->method('save');
        $history = new NickHistoryService($historyRepo);

        $codec = $this->createMock(RescueTokenCodec::class);
        $codec->expects(self::once())->method('generate')->willReturn('uuid-token-v4');
        $codec->expects(self::once())->method('hash')->with('uuid-token-v4')->willReturn($tokenHash);

        $hasher = $this->createMock(PasswordHasher::class);
        $hasher->expects(self::once())->method('hash')->with('secret123')->willReturn('hashed_password');

        $handler = $this->handler(
            repository: $repository,
            hasher: $hasher,
            codec: $codec,
            eventPublisher: $eventPublisher,
            history: $history,
            mode: RegistrationMode::Token,
        );

        $result = $handler->handle($this->command(
            nickname: 'NewNick',
            password: 'secret123',
            email: 'optional@example.com',
            language: 'es',
            actorNickname: 'OperNick',
            actorAccountId: 42,
        ));

        self::assertSame(SaregisterNickOutcome::RegisteredToken, $result->outcome);
        self::assertSame('NewNick', $result->nickname);
        self::assertSame('uuid-token-v4', $result->token);
    }

    #[Test]
    public function registersImmediatelyInEmailModeAndDispatchesMail(): void
    {
        $tokenHash = hash('sha256', 'uuid-token-v4');

        $mailSender = $this->createMock(SaregisterMailSender::class);
        $mailSender->expects(self::once())->method('sendRescueToken')
            ->with('EmailNick', 'user@example.com', 'uuid-token-v4', 'en');

        $codec = $this->createStub(RescueTokenCodec::class);
        $codec->method('generate')->willReturn('uuid-token-v4');
        $codec->method('hash')->willReturn($tokenHash);

        $handler = $this->handler(
            codec: $codec,
            mailSender: $mailSender,
            mode: RegistrationMode::Email,
        );

        $result = $handler->handle($this->command(
            nickname: 'EmailNick',
            email: 'user@example.com',
        ));

        self::assertSame(SaregisterNickOutcome::RegisteredEmail, $result->outcome);
        self::assertSame('EmailNick', $result->nickname);
        self::assertSame('user@example.com', $result->email);
    }

    #[Test]
    public function returnsMailDeliveryFailedWhenMailSenderThrows(): void
    {
        $tokenHash = hash('sha256', 'uuid-token-v4');

        $mailSender = $this->createMock(SaregisterMailSender::class);
        $mailSender->expects(self::once())->method('sendRescueToken')->willThrowException(new RuntimeException('SMTP down'));

        $codec = $this->createStub(RescueTokenCodec::class);
        $codec->method('generate')->willReturn('uuid-token-v4');
        $codec->method('hash')->willReturn($tokenHash);

        $handler = $this->handler(
            codec: $codec,
            mailSender: $mailSender,
            mode: RegistrationMode::Email,
        );

        $result = $handler->handle($this->command(
            nickname: 'EmailNick',
            email: 'user@example.com',
        ));

        self::assertSame(SaregisterNickOutcome::MailDeliveryFailed, $result->outcome);
        self::assertSame('EmailNick', $result->nickname);
    }

    #[Test]
    public function throwsLogicExceptionIfGeneratedTokenCannotBeHashed(): void
    {
        $codec = $this->createStub(RescueTokenCodec::class);
        $codec->method('generate')->willReturn('invalid-token');
        $codec->method('hash')->willReturn(null);

        $handler = $this->handler(codec: $codec);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Generated rescue token is invalid.');
        $handler->handle($this->command());
    }

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-10-30T15:00:00Z');
    }

    private function handler(
        ?RegisterNickRepository $repository = null,
        ?PasswordHasher $hasher = null,
        ?RescueTokenCodec $codec = null,
        ?RegistrationEventPublisher $eventPublisher = null,
        ?NickHistoryService $history = null,
        ?EmailDomainPolicy $emailDomainPolicy = null,
        ?SaregisterMailSender $mailSender = null,
        ?NicknamePolicyInterface $nicknamePolicy = null,
        RegistrationMode $mode = RegistrationMode::Token,
    ): SaregisterNickHandler {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        $modeQuery = $this->createStub(RegistrationModeQuery::class);
        $modeQuery->method('current')->willReturn($mode);

        if (null === $emailDomainPolicy) {
            $defaultPolicy = $this->createStub(EmailDomainPolicy::class);
            $defaultPolicy->method('isBlocked')->willReturn(false);
            $emailPolicy = $defaultPolicy;
        } else {
            $emailPolicy = $emailDomainPolicy;
        }

        $historyService = $history ?? new NickHistoryService(
            $this->createStub(NickHistoryRepositoryInterface::class),
        );

        $defaultPolicy = $this->createStub(NicknamePolicyInterface::class);
        $defaultPolicy->method('isValid')->willReturn(true);
        $defaultPolicy->method('getMaximumLength')->willReturn(30);

        $defaultRepo = $this->createStub(RegisterNickRepository::class);
        $defaultRepo->method('save')->willReturnCallback(static function (RegisteredNick $nick): void {
            new ReflectionProperty($nick, 'id')->setValue($nick, 100);
        });

        return new SaregisterNickHandler(
            nickRepository: $repository ?? $defaultRepo,
            passwordHasher: $hasher ?? $this->createStub(PasswordHasher::class),
            tokenGenerator: $codec ?? $this->createStub(RescueTokenCodec::class),
            clock: $clock,
            registrationMode: $modeQuery,
            eventPublisher: $eventPublisher ?? $this->createStub(RegistrationEventPublisher::class),
            history: $historyService,
            emailDomainPolicy: $emailPolicy,
            mailSender: $mailSender ?? $this->createStub(SaregisterMailSender::class),
            nicknamePolicy: $nicknamePolicy ?? $defaultPolicy,
        );
    }

    private function command(
        string $nickname = 'Alice',
        string $password = 'password123',
        ?string $email = null,
        string $language = 'en',
        string $actorNickname = 'OperNick',
        ?int $actorAccountId = 1,
    ): SaregisterNick {
        return new SaregisterNick(
            nickname: $nickname,
            password: $password,
            email: $email,
            language: $language,
            actorNickname: $actorNickname,
            actorAccountId: $actorAccountId,
            actorIrcOperator: true,
        );
    }
}
