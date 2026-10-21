<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\Recover;

use App\NickServ\Application\Event\NickRecoveredEvent;
use App\NickServ\Application\Port\In\RescueTokenValidation;
use App\NickServ\Application\Port\In\RescueTokenValidationOutcome;
use App\NickServ\Application\Port\In\RescueTokenValidationResult;
use App\NickServ\Application\Port\In\RescueTokenValidator;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PasswordHasher;
use App\NickServ\Application\Port\Out\RecoverEventPublisher;
use App\NickServ\Application\Port\Out\RecoveryPasswordGenerator;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\UseCase\Recover\RecoverNick;
use App\NickServ\Application\UseCase\Recover\RecoverNickHandler;
use App\NickServ\Application\UseCase\Recover\RecoverNickOutcome;
use App\NickServ\Application\UseCase\Recover\RecoverNickResult;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoverNickHandler::class)]
#[CoversClass(RecoverNick::class)]
#[CoversClass(RecoverNickResult::class)]
final class RecoverNickHandlerTest extends TestCase
{
    public function testMissingTokenOnlyReturnsInstructionsWithoutReadingOrMutatingCredentials(): void
    {
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->expects(self::never())->method('findByNick');
        $validator = $this->createMock(RescueTokenValidator::class);
        $validator->expects(self::never())->method('validate');
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::never())->method('rotateAfterRecovery');
        $result = $this->handler($repository, $validator, $persistence)->handle(new RecoverNick('User'));
        self::assertSame(RecoverNickOutcome::Instructions, $result->outcome);
        self::assertSame('User', $result->nickname);
        self::assertNull($result->temporaryPassword);
        self::assertNull($result->rescueToken);
    }

    public function testUnknownNicknameIsRejected(): void
    {
        $result = $this->handler()->handle(new RecoverNick('Unknown', 'token'));
        self::assertSame(RecoverNickOutcome::NotRegistered, $result->outcome);
    }

    /** @return iterable<string, array{string, RecoverNickOutcome}> */
    public static function blockedStatuses(): iterable
    {
        yield 'pending' => ['isPending', RecoverNickOutcome::Pending];
        yield 'suspended' => ['isSuspended', RecoverNickOutcome::Suspended];
        yield 'forbidden' => ['isForbidden', RecoverNickOutcome::Forbidden];
        yield 'pending deletion' => ['isPendingDeletion', RecoverNickOutcome::PendingDeletion];
    }

    #[DataProvider('blockedStatuses')]
    public function testPreservesAccountStatusRestrictions(string $method, RecoverNickOutcome $expected): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method($method)->willReturn(true);
        $account->method('getReason')->willReturn('policy');
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($account);
        $validator = $this->createMock(RescueTokenValidator::class);
        $validator->expects(self::never())->method('validate');
        $result = $this->handler($repository, $validator)->handle(new RecoverNick('User', 'token'));
        self::assertSame($expected, $result->outcome);
    }

    /** @return iterable<string, array{RescueTokenValidationOutcome, RecoverNickOutcome}> */
    public static function rejectedTokens(): iterable
    {
        yield 'invalid' => [RescueTokenValidationOutcome::Invalid, RecoverNickOutcome::InvalidToken];
        yield 'missing confirmed credential' => [RescueTokenValidationOutcome::Unavailable, RecoverNickOutcome::CredentialUnavailable];
        yield 'throttled' => [RescueTokenValidationOutcome::Throttled, RecoverNickOutcome::Throttled];
    }

    #[DataProvider('rejectedTokens')]
    public function testRejectedTokenNeverChangesCredentials(RescueTokenValidationOutcome $validation, RecoverNickOutcome $expected): void
    {
        $account = $this->account();
        $repository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($account);
        $repository->expects(self::never())->method('save');
        $validator = $this->createMock(RescueTokenValidator::class);
        $validator->expects(self::once())->method('validate')->with(self::callback(static fn (RescueTokenValidation $input): bool => 42 === $input->accountId && 'bad' === $input->token && 'ip:127.0.0.1' === $input->clientKey))->willReturn(new RescueTokenValidationResult($validation, 123));
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::never())->method('rotateAfterRecovery');
        $events = $this->createMock(RecoverEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $result = $this->handler($repository, $validator, $persistence, $events)->handle(new RecoverNick('User', 'bad', clientKey: 'ip:127.0.0.1'));
        self::assertSame($expected, $result->outcome);
        self::assertNull($result->temporaryPassword);
        self::assertNull($result->rescueToken);
        self::assertSame('old-password', $account->getPasswordHash());
    }

    public function testAtomicRotationPublishesOnlyAfterSuccessAndPrivatelyReturnsBothSecrets(): void
    {
        $account = $this->account();
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($account);
        $validator = $this->createStub(RescueTokenValidator::class);
        $validator->method('validate')->willReturn(new RescueTokenValidationResult(RescueTokenValidationOutcome::Valid));
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::once())->method('rotateAfterRecovery')->with(42, 'old-token-hash', 'new-password-hash', 'new-token-hash', self::isInstanceOf(DateTimeImmutable::class))->willReturn(true);
        $published = [];
        $events = $this->createMock(RecoverEventPublisher::class);
        $events->expects(self::exactly(2))->method('publish')->willReturnCallback(static function (object $event) use (&$published): void { $published[] = $event; });
        $result = $this->handler($repository, $validator, $persistence, $events)->handle(new RecoverNick('User', 'valid', 'Actor', 7, 'ip', 'host'));
        self::assertSame(RecoverNickOutcome::PasswordReset, $result->outcome);
        self::assertSame('new-password', $result->temporaryPassword);
        self::assertSame('new-token', $result->rescueToken);
        self::assertInstanceOf(NickPasswordHashAvailable::class, $published[0]);
        self::assertSame('new-password-hash', $published[0]->passwordHash);
        self::assertInstanceOf(NickRecoveredEvent::class, $published[1]);
        self::assertSame('token', $published[1]->method);
        self::assertSame('Actor', $published[1]->performedBy);
        self::assertStringNotContainsString('new-token', serialize($published));
    }

    public function testFailedCompareAndSwapLeaksNoGeneratedCredentialsAndPublishesNothing(): void
    {
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($this->account());
        $validator = $this->createStub(RescueTokenValidator::class);
        $validator->method('validate')->willReturn(new RescueTokenValidationResult(RescueTokenValidationOutcome::Valid));
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::once())->method('rotateAfterRecovery')->willReturn(false);
        $events = $this->createMock(RecoverEventPublisher::class);
        $events->expects(self::never())->method('publish');
        $result = $this->handler($repository, $validator, $persistence, $events)->handle(new RecoverNick('User', 'stale'));
        self::assertSame(RecoverNickOutcome::InvalidToken, $result->outcome);
        self::assertNull($result->temporaryPassword);
        self::assertNull($result->rescueToken);
    }

    public function testNullConfirmedHashFailsClosedEvenWhenValidatorReturnsValid(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($account);
        $validator = $this->createStub(RescueTokenValidator::class);
        $validator->method('validate')->willReturn(new RescueTokenValidationResult(RescueTokenValidationOutcome::Valid));
        self::assertSame(RecoverNickOutcome::CredentialUnavailable, $this->handler($repository, $validator)->handle(new RecoverNick('User', 'token'))->outcome);
    }

    public function testRecoveryUsesHashSnapshotTakenBeforeValidation(): void
    {
        $hash = 'old-token-hash';
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $account->method('getRescueTokenHash')->willReturnCallback(static function () use (&$hash): string { return $hash; });
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($account);
        $validator = $this->createStub(RescueTokenValidator::class);
        $validator->method('validate')->willReturnCallback(static function () use (&$hash): RescueTokenValidationResult {
            $hash = 'concurrent-new-hash';

            return new RescueTokenValidationResult(RescueTokenValidationOutcome::Valid);
        });
        $persistence = $this->createMock(RescueCredentialPersistence::class);
        $persistence->expects(self::once())->method('rotateAfterRecovery')->with(42, 'old-token-hash', 'new-password-hash', 'new-token-hash', self::isInstanceOf(DateTimeImmutable::class))->willReturn(false);
        $events = $this->createMock(RecoverEventPublisher::class);
        $events->expects(self::never())->method('publish');
        self::assertSame(RecoverNickOutcome::InvalidToken, $this->handler($repository, $validator, $persistence, $events)->handle(new RecoverNick('User', 'token'))->outcome);
    }

    public function testInvalidGeneratedCredentialFailsClosedBeforePersistence(): void
    {
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findByNick')->willReturn($this->account());
        $validator = $this->createStub(RescueTokenValidator::class);
        $validator->method('validate')->willReturn(new RescueTokenValidationResult(RescueTokenValidationOutcome::Valid));
        $codec = $this->createStub(RescueTokenCodec::class);
        $codec->method('generate')->willReturn('invalid-generated');
        $codec->method('hash')->willReturn(null);
        $credentials = $this->createMock(RescueCredentialPersistence::class);
        $credentials->expects(self::never())->method('rotateAfterRecovery');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The generated rescue token is invalid.');
        $this->handler($repository, $validator, $credentials, codec: $codec)->handle(new RecoverNick('User', 'token'));
    }

    private function account(): RegisteredNick
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $account->method('getRescueTokenHash')->willReturn('old-token-hash');
        $account->method('getPasswordHash')->willReturn('old-password');

        return $account;
    }

    private function handler(?RegisteredNickRepositoryInterface $repository = null, ?RescueTokenValidator $validator = null, ?RescueCredentialPersistence $persistence = null, ?RecoverEventPublisher $events = null, ?RescueTokenCodec $codec = null): RecoverNickHandler
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-21 UTC'));
        if (null === $codec) {
            $codec = $this->createStub(RescueTokenCodec::class);
            $codec->method('generate')->willReturn('new-token');
            $codec->method('hash')->willReturn('new-token-hash');
        }
        $passwords = $this->createStub(RecoveryPasswordGenerator::class);
        $passwords->method('generate')->willReturn('new-password');
        $hasher = $this->createStub(PasswordHasher::class);
        $hasher->method('hash')->willReturn('new-password-hash');

        return new RecoverNickHandler($repository ?? $this->createStub(RegisteredNickRepositoryInterface::class), $hasher, $passwords, $validator ?? $this->createStub(RescueTokenValidator::class), $codec, $persistence ?? $this->createStub(RescueCredentialPersistence::class), $events ?? $this->createStub(RecoverEventPublisher::class), $clock);
    }
}
