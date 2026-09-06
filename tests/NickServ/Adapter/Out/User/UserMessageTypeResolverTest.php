<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\Irc\Application\Port\In\SenderView;
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
        $sender = new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip');

        self::assertSame('NOTICE', $resolver->resolve($sender));
        self::assertSame('NOTICE', $resolver->resolveByNick('Nick'));
    }

    #[Test]
    public function resolveReturnsAccountMessageTypeWhenRegistered(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getMessageType')->willReturn('PRIVMSG');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $resolver = new UserMessageTypeResolver($repo);
        $sender = new SenderView('UID', 'RegNick', 'i', 'h', 'c', 'ip');

        self::assertSame('PRIVMSG', $resolver->resolve($sender));
        self::assertSame('PRIVMSG', $resolver->resolveByNick('RegNick'));
    }
}
