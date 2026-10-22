<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\UseCase\IssueMigrationRescueToken;

use App\NickServ\Adapter\Out\InMemory\InMemoryTemporaryRescueTokenStore;
use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use App\NickServ\Application\Model\UserMessagePreference;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RescueTokenNotifier;
use App\NickServ\Application\UseCase\IssueMigrationRescueToken\IssueMigrationRescueToken;
use App\NickServ\Application\UseCase\IssueMigrationRescueToken\IssueMigrationRescueTokenHandler;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(IssueMigrationRescueTokenHandler::class)]
#[CoversClass(IssueMigrationRescueToken::class)]
final class IssueMigrationRescueTokenHandlerTest extends TestCase
{
    public function testEachAuthenticationReplacesOnlyMemoryChallengeUntilConfirmation(): void
    {
        $account = $this->account();
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);
        $repo->expects(self::never())->method('save');
        $tokens = [];
        $notifier = $this->createMock(RescueTokenNotifier::class);
        $notifier->expects(self::exactly(2))->method('issued')->willReturnCallback(static function (string $uid, string $token, string $language, UserMessagePreference $preference) use (&$tokens): void {
            self::assertSame('UID1', $uid);
            self::assertSame('es', $language);
            self::assertSame(UserMessagePreference::Notice, $preference);
            $tokens[] = $token;
        });
        $codec = new UuidRescueTokenCodec();
        $temporary = new InMemoryTemporaryRescueTokenStore();
        $handler = new IssueMigrationRescueTokenHandler($repo, $codec, $temporary, $notifier);
        $now = new DateTimeImmutable('2026-09-06');
        $command = new IssueMigrationRescueToken(42, 'UID1', $now);
        $handler->handle($command);
        $handler->handle($command);
        self::assertCount(2, $tokens);
        $firstHash = $codec->hash($tokens[0]);
        $lastHash = $codec->hash($tokens[1]);
        self::assertIsString($firstHash);
        self::assertIsString($lastHash);
        self::assertFalse($temporary->matches(42, $firstHash, $now));
        self::assertTrue($temporary->matches(42, $lastHash, $now));
        self::assertFalse($temporary->matches(42, $lastHash, $now->modify('+3600 seconds')));
        self::assertNull($account->getRescueTokenHash());
        $account->replaceRescueTokenHash($lastHash);
        $handler->handle($command);
        self::assertSame($lastHash, $account->getRescueTokenHash());
    }

    public function testMissingPendingAndCapacityExhaustionNeverClaimDelivery(): void
    {
        $pending = RegisteredNick::createPending('Pending', 'hash', 'p@example.com', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        foreach ([null, $pending, $this->account()] as $account) {
            $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
            $repo->method('findById')->willReturn($account);
            $notifier = $this->createMock(RescueTokenNotifier::class);
            $notifier->expects(self::never())->method('issued');
            $temporary = new InMemoryTemporaryRescueTokenStore(1);
            $now = new DateTimeImmutable('2026-09-06');
            $temporary->replace(99, str_repeat('a', 64), $now->modify('+1 hour'));
            $handler = new IssueMigrationRescueTokenHandler($repo, new UuidRescueTokenCodec(), $temporary, $notifier);
            $handler->handle(new IssueMigrationRescueToken(42, 'UID1', $now));
            self::assertTrue($temporary->matches(99, str_repeat('a', 64), $now));
        }
    }

    public function testAccountMessagePreferenceIsPreserved(): void
    {
        $account = $this->account();
        $account->switchMsg(true);
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);
        $notifier = $this->createMock(RescueTokenNotifier::class);
        $notifier->expects(self::once())->method('issued')->with('UID1', self::anything(), 'es', UserMessagePreference::PrivateMessage);
        new IssueMigrationRescueTokenHandler($repo, new UuidRescueTokenCodec(), new InMemoryTemporaryRescueTokenStore(), $notifier)->handle(new IssueMigrationRescueToken(42, 'UID1', new DateTimeImmutable('2026-09-06')));
    }

    private function account(): RegisteredNick
    {
        $account = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'es', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $account->activate();
        new ReflectionProperty($account, 'id')->setValue($account, 42);

        return $account;
    }
}
