<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Recover;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RecoverEventPublisher;
use App\NickServ\Application\Port\Out\RecoveryMailSender;
use App\NickServ\Application\Port\Out\RecoveryPasswordGenerator;
use App\NickServ\Application\Port\Out\RecoveryTokenStore;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\UseCase\Recover\RecoverNick;
use App\NickServ\Application\UseCase\Recover\RecoverNickHandler;
use App\NickServ\Application\UseCase\Recover\RecoverNickOutcome;
use App\NickServ\Application\UseCase\Recover\RecoverNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\Event\NickPasswordChangedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RecoverNickHandler::class)]
#[CoversClass(RecoverNick::class)]
#[CoversClass(RecoverNickResult::class)]
final class RecoverNickHandlerTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }

    #[Test]
    public function returnsNotRegisteredWhenAccountNotFound(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('Unknown')->willReturn(null);

        $handler = $this->createHandler(repository: $repository);
        $result = $handler->handle(new RecoverNick(nickname: 'Unknown'));

        self::assertSame(RecoverNickOutcome::NotRegistered, $result->outcome);
        self::assertSame('Unknown', $result->nickname);
    }

    #[Test]
    public function returnsPendingWhenAccountIsPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('PendingUser')->willReturn($account);

        $handler = $this->createHandler(repository: $repository);
        $result = $handler->handle(new RecoverNick(nickname: 'PendingUser'));

        self::assertSame(RecoverNickOutcome::Pending, $result->outcome);
        self::assertSame('PendingUser', $result->nickname);
    }

    #[Test]
    public function returnsSuspendedWhenAccountIsSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(true);
        $account->method('getReason')->willReturn('Policy violation');

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('SuspendedUser')->willReturn($account);

        $handler = $this->createHandler(repository: $repository);
        $result = $handler->handle(new RecoverNick(nickname: 'SuspendedUser'));

        self::assertSame(RecoverNickOutcome::Suspended, $result->outcome);
        self::assertSame('SuspendedUser', $result->nickname);
        self::assertSame('Policy violation', $result->reason);
    }

    #[Test]
    public function returnsForbiddenWhenAccountIsForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ForbiddenUser')->willReturn($account);

        $handler = $this->createHandler(repository: $repository);
        $result = $handler->handle(new RecoverNick(nickname: 'ForbiddenUser'));

        self::assertSame(RecoverNickOutcome::Forbidden, $result->outcome);
        self::assertSame('ForbiddenUser', $result->nickname);
    }

    #[Test]
    public function returnsNoEmailWhenAccountHasNoEmail(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('');

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('NoEmailUser')->willReturn($account);

        $handler = $this->createHandler(repository: $repository);
        $result = $handler->handle(new RecoverNick(nickname: 'NoEmailUser'));

        self::assertSame(RecoverNickOutcome::NoEmail, $result->outcome);
        self::assertSame('NoEmailUser', $result->nickname);
    }

    #[Test]
    public function returnsThrottledWhenWithinMinInterval(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('user@example.com');

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ThrottledUser')->willReturn($account);

        $tokenStore = $this->createMock(RecoveryTokenStore::class);
        $tokenStore->expects(self::once())->method('getLastRecoverAt')
            ->with('ThrottledUser')
            ->willReturn($this->now->modify('-60 seconds'));

        $handler = $this->createHandler(repository: $repository, tokenStore: $tokenStore, minIntervalSeconds: 300);
        $result = $handler->handle(new RecoverNick(nickname: 'ThrottledUser'));

        self::assertSame(RecoverNickOutcome::Throttled, $result->outcome);
        self::assertSame(240, $result->retryAfterSeconds);
    }

    #[Test]
    public function returnsMailDeliveryFailedWhenMailerThrows(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('user@example.com');
        $account->method('getLanguage')->willReturn('es');

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('MailFailUser')->willReturn($account);

        $tokenStore = $this->createMock(RecoveryTokenStore::class);
        $tokenStore->expects(self::once())->method('getLastRecoverAt')->willReturn(null);
        $tokenStore->expects(self::once())->method('store');
        $tokenStore->expects(self::never())->method('recordRecover');

        $tokenGenerator = $this->createMock(VerificationTokenGenerator::class);
        $tokenGenerator->expects(self::once())->method('generate')->willReturn('token-123');

        $mailSender = $this->createMock(RecoveryMailSender::class);
        $mailSender->expects(self::once())->method('sendRecovery')
            ->with('MailFailUser', 'user@example.com', 'token-123', 'es')
            ->willThrowException(new RuntimeException('mail error'));

        $handler = $this->createHandler(
            repository: $repository,
            tokenStore: $tokenStore,
            tokenGenerator: $tokenGenerator,
            mailSender: $mailSender,
        );

        $result = $handler->handle(new RecoverNick(nickname: 'MailFailUser'));

        self::assertSame(RecoverNickOutcome::MailDeliveryFailed, $result->outcome);
    }

    #[Test]
    public function requestsTokenAndDispatchesEmailSuccessfully(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('user@example.com');
        $account->method('getLanguage')->willReturn('en');

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ValidUser')->willReturn($account);

        $tokenStore = $this->createMock(RecoveryTokenStore::class);
        $tokenStore->expects(self::once())->method('getLastRecoverAt')->willReturn(null);
        $tokenStore->expects(self::once())->method('store')
            ->with('ValidUser', 'token-abc', $this->now->modify('+3600 seconds'));
        $tokenStore->expects(self::once())->method('recordRecover')->with('ValidUser');

        $tokenGenerator = $this->createMock(VerificationTokenGenerator::class);
        $tokenGenerator->expects(self::once())->method('generate')->willReturn('token-abc');

        $mailSender = $this->createMock(RecoveryMailSender::class);
        $mailSender->expects(self::once())->method('sendRecovery')->with('ValidUser', 'user@example.com', 'token-abc', 'en');

        $handler = $this->createHandler(
            repository: $repository,
            tokenStore: $tokenStore,
            tokenGenerator: $tokenGenerator,
            mailSender: $mailSender,
        );

        $result = $handler->handle(new RecoverNick(nickname: 'ValidUser'));

        self::assertSame(RecoverNickOutcome::TokenSent, $result->outcome);
        self::assertSame('user@example.com', $result->email);
    }

    #[Test]
    public function returnsInvalidTokenWhenTokenStoreRejects(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ConsumeUser')->willReturn($account);

        $tokenStore = $this->createMock(RecoveryTokenStore::class);
        $tokenStore->expects(self::once())->method('consume')->with('ConsumeUser', 'wrong-token')->willReturn(false);

        $handler = $this->createHandler(repository: $repository, tokenStore: $tokenStore);
        $result = $handler->handle(new RecoverNick(nickname: 'ConsumeUser', token: 'wrong-token'));

        self::assertSame(RecoverNickOutcome::InvalidToken, $result->outcome);
        self::assertSame('ConsumeUser', $result->nickname);
    }

    #[Test]
    public function consumesTokenAndResetsPasswordSuccessfully(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getId')->willReturn(42);
        $account->method('getPasswordHash')->willReturn('hashed-pw');
        $account->expects(self::once())->method('changePassword')->with('hashed-pw');

        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::once())->method('findByNick')->with('ConsumeUser')->willReturn($account);
        $repository->expects(self::once())->method('save')->with($account);

        $tokenStore = $this->createMock(RecoveryTokenStore::class);
        $tokenStore->expects(self::once())->method('consume')->with('ConsumeUser', 'valid-token')->willReturn(true);

        $passwordGenerator = $this->createMock(RecoveryPasswordGenerator::class);
        $passwordGenerator->expects(self::once())->method('generate')->willReturn('temp-secret12');

        $passwordHasher = $this->createMock(PasswordHasher::class);
        $passwordHasher->expects(self::once())->method('hash')->with('temp-secret12')->willReturn('hashed-pw');

        $eventPublisher = $this->createMock(RecoverEventPublisher::class);
        $eventPublisher->expects(self::exactly(2))->method('publish')->with(self::callback(
            static fn (object $event): bool => $event instanceof NickPasswordHashAvailable || $event instanceof NickPasswordChangedEvent,
        ));

        $handler = $this->createHandler(
            repository: $repository,
            passwordHasher: $passwordHasher,
            passwordGenerator: $passwordGenerator,
            tokenStore: $tokenStore,
            eventPublisher: $eventPublisher,
        );

        $result = $handler->handle(new RecoverNick(
            nickname: 'ConsumeUser',
            token: 'valid-token',
            senderNick: 'Oper',
            senderAccountId: 1,
            senderIp: '127.0.0.1',
            senderHost: 'oper@localhost',
        ));

        self::assertSame(RecoverNickOutcome::PasswordReset, $result->outcome);
        self::assertSame('ConsumeUser', $result->nickname);
        self::assertSame('temp-secret12', $result->temporaryPassword);
    }

    private function createHandler(
        ?RegisteredNickRepositoryInterface $repository = null,
        ?PasswordHasher $passwordHasher = null,
        ?VerificationTokenGenerator $tokenGenerator = null,
        ?RecoveryPasswordGenerator $passwordGenerator = null,
        ?RecoveryTokenStore $tokenStore = null,
        ?RecoveryMailSender $mailSender = null,
        ?RecoverEventPublisher $eventPublisher = null,
        int $ttlSeconds = 3600,
        int $minIntervalSeconds = 300,
    ): RecoverNickHandler {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now);

        return new RecoverNickHandler(
            nickRepository: $repository ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            passwordHasher: $passwordHasher ?? $this->createStub(PasswordHasher::class),
            tokenGenerator: $tokenGenerator ?? $this->createStub(VerificationTokenGenerator::class),
            passwordGenerator: $passwordGenerator ?? $this->createStub(RecoveryPasswordGenerator::class),
            tokenStore: $tokenStore ?? $this->createStub(RecoveryTokenStore::class),
            mailSender: $mailSender ?? $this->createStub(RecoveryMailSender::class),
            eventPublisher: $eventPublisher ?? $this->createStub(RecoverEventPublisher::class),
            clock: $clock,
            recoverTokenTtlSeconds: $ttlSeconds,
            recoverMinIntervalSeconds: $minIntervalSeconds,
        );
    }
}
