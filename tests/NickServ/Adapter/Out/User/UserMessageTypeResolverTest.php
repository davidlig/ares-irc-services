<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\Out\User\UserMessageTypeResolver;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserMessageTypeResolver::class)]
final class UserMessageTypeResolverTest extends TestCase
{
    #[Test]
    public function resolveReturnsNoticeWhenNoAccount(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn(null);
        $resolver = new UserMessageTypeResolver($repo);
        self::assertFalse($resolver->prefersPrivateMessages('Nick'));
    }

    #[Test]
    public function resolveReturnsAccountMessageTypeWhenRegistered(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('prefersPrivateMessages')->willReturn(true);
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $resolver = new UserMessageTypeResolver($repo);
        self::assertTrue($resolver->prefersPrivateMessages('RegNick'));
    }
}
