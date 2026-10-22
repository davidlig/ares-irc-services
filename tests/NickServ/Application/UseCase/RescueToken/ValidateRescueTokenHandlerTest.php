<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\RescueToken;

use App\NickServ\Adapter\Out\InMemory\InMemoryRescueAttemptLimiter;
use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Application\Port\In\RescueTokenValidation;
use App\NickServ\Application\Port\In\RescueTokenValidationOutcome;
use App\NickServ\Application\Port\In\RescueTokenValidationResult;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\UseCase\RescueToken\ValidateRescueTokenHandler;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidateRescueTokenHandler::class)]
#[CoversClass(RescueTokenValidation::class)]
#[CoversClass(RescueTokenValidationResult::class)]
final class ValidateRescueTokenHandlerTest extends TestCase
{
    public function testOnlyActiveConfirmedCredentialCanAuthorize(): void
    {
        $now = new DateTimeImmutable('2026-10-21T12:00:00Z');
        $codec = new UuidRescueTokenCodec();
        $token = '550e8400-e29b-41d4-a716-446655440000';
        $account = RegisteredNick::createPending('Alice', 'password-hash', 'alice@example.test', 'en', $now->modify('+1 hour'), $now);
        $account->replaceRescueTokenHash((string) $codec->hash($token));
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repository->method('findById')->willReturn($account);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $handler = new ValidateRescueTokenHandler($repository, $codec, new InMemoryRescueAttemptLimiter(), $clock);
        self::assertSame(RescueTokenValidationOutcome::Unavailable, $handler->validate(new RescueTokenValidation(1, $token, 'client'))->outcome);
        $account->activate();
        self::assertSame(RescueTokenValidationOutcome::Valid, $handler->validate(new RescueTokenValidation(1, strtoupper($token), 'client'))->outcome);
        self::assertSame(RescueTokenValidationOutcome::Invalid, $handler->validate(new RescueTokenValidation(1, 'bad', 'client'))->outcome);
    }

    public function testSharedClientAndAccountBudgetsBlockFurtherAttempts(): void
    {
        $now = new DateTimeImmutable('2026-10-21T12:00:00Z');
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isRegistered')->willReturn(true);
        $account->method('getRescueTokenHash')->willReturn(str_repeat('a', 64));
        $repository->method('findById')->willReturn($account);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($now);
        $handler = new ValidateRescueTokenHandler($repository, new UuidRescueTokenCodec(), new InMemoryRescueAttemptLimiter(), $clock);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(RescueTokenValidationOutcome::Invalid, $handler->validate(new RescueTokenValidation(1, 'bad', 'client'))->outcome);
        }
        $blocked = $handler->validate(new RescueTokenValidation(1, 'bad', 'other-client'));
        self::assertSame(RescueTokenValidationOutcome::Throttled, $blocked->outcome);
        self::assertGreaterThan(0, $blocked->retryAfterSeconds);
    }

    public function testMissingAccountAndLegacyAccountAreUnavailable(): void
    {
        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isRegistered')->willReturn(true);
        $repository->method('findById')->willReturnOnConsecutiveCalls(null, $account);
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable());
        $handler = new ValidateRescueTokenHandler($repository, new UuidRescueTokenCodec(), new InMemoryRescueAttemptLimiter(), $clock);
        self::assertSame(RescueTokenValidationOutcome::Unavailable, $handler->validate(new RescueTokenValidation(1, 'bad', 'client'))->outcome);
        self::assertSame(RescueTokenValidationOutcome::Unavailable, $handler->validate(new RescueTokenValidation(1, 'bad', 'client'))->outcome);
    }
}
