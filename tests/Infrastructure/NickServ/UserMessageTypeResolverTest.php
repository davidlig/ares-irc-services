<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ;

use App\Application\Port\SenderView;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
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
        $sender = new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip');

        self::assertSame('NOTICE', $resolver->resolve($sender));
        self::assertSame('NOTICE', $resolver->resolveByNick('Nick'));
    }

    #[Test]
    public function resolveReturnsAccountMessageTypeWhenRegistered(): void
    {
        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getMessageType')->willReturn('PRIVMSG');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $resolver = new UserMessageTypeResolver($repo);
        $sender = new SenderView('UID', 'RegNick', 'i', 'h', 'c', 'ip');

        self::assertSame('PRIVMSG', $resolver->resolve($sender));
        self::assertSame('PRIVMSG', $resolver->resolveByNick('RegNick'));
    }
}
