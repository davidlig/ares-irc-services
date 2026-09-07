<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\Out\User\NickAccountResolver;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickAccountResolver::class)]
final class NickAccountResolverTest extends TestCase
{
    #[Test]
    public function findIdByNickReturnsIdWhenFound(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $repo->method('findByNick')->willReturnCallback(static fn (string $nick): ?RegisteredNick => 'alice' === $nick ? $account : null);

        $resolver = new NickAccountResolver($repo, 'en');

        self::assertSame(10, $resolver->findIdByNick('alice'));
        self::assertNull($resolver->findIdByNick('bob'));
    }

    #[Test]
    public function findNicknameByIdReturnsNicknameWhenFound(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('Alice');
        $repo->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => 10 === $id ? $account : null);

        $resolver = new NickAccountResolver($repo, 'en');

        self::assertSame('Alice', $resolver->findNicknameById(10));
        self::assertNull($resolver->findNicknameById(99));
    }

    #[Test]
    public function findAccountByNickReturnsDataWhenFound(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('Alice');
        $account->method('getLanguage')->willReturn('fr');
        $account->method('getTimezone')->willReturn('Europe/Paris');
        $account->method('isRegistered')->willReturn(true);
        $repo->method('findByNick')->willReturnCallback(static fn (string $nick): ?RegisteredNick => 'alice' === $nick ? $account : null);

        $resolver = new NickAccountResolver($repo, 'en');

        $data = $resolver->findAccountByNick('alice');
        self::assertNotNull($data);
        self::assertSame(10, $data->id);
        self::assertSame('Alice', $data->nickname);
        self::assertSame('fr', $data->language);
        self::assertSame('Europe/Paris', $data->timezone);
        self::assertTrue($data->registered);

        self::assertNull($resolver->findAccountByNick('bob'));
    }

    #[Test]
    public function findAccountByIdReturnsDataWhenFound(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('Alice');
        $account->method('getLanguage')->willReturn('fr');
        $account->method('getTimezone')->willReturn('Europe/Paris');
        $account->method('isRegistered')->willReturn(true);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getEmail')->willReturn('alice@example.com');
        $repo->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => 10 === $id ? $account : null);

        $resolver = new NickAccountResolver($repo, 'en');

        $data = $resolver->findAccountById(10);
        self::assertNotNull($data);
        self::assertSame(10, $data->id);
        self::assertSame('Alice', $data->nickname);
        self::assertSame('fr', $data->language);
        self::assertSame('Europe/Paris', $data->timezone);
        self::assertTrue($data->registered);
        self::assertFalse($data->suspended);
        self::assertSame('alice@example.com', $data->email);

        self::assertNull($resolver->findAccountById(99));
    }

    #[Test]
    public function findAccountByNickUsesDefaultTimezoneWhenAccountTimezoneIsNull(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('Alice');
        $account->method('getLanguage')->willReturn('es');
        $account->method('getTimezone')->willReturn(null);
        $account->method('isRegistered')->willReturn(false);
        $repo->method('findByNick')->willReturn($account);

        $resolver = new NickAccountResolver($repo, 'en');

        $data = $resolver->findAccountByNick('alice');
        self::assertNotNull($data);
        self::assertSame('UTC', $data->timezone);
        self::assertFalse($data->registered);
    }

    #[Test]
    public function getLanguageReturnsAccountLanguageOrFallback(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('de');
        $repo->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => 10 === $id ? $account : null);

        $resolver = new NickAccountResolver($repo, 'en');

        self::assertSame('de', $resolver->getLanguage(10));
        self::assertSame('en', $resolver->getLanguage(99));
    }
}
