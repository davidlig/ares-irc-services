<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\NickServClientKeyResolver;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServClientKeyResolver::class)]
final class NickServClientKeyResolverTest extends TestCase
{
    #[Test]
    public function prefersIpWhenNonEmptyAndNotWildcard(): void
    {
        $resolver = new NickServClientKeyResolver();
        $sender = new SenderView('UID1', 'N', 'i', 'h', 'c', 'aGVsbG8=', false, false);

        self::assertSame('ip:aGVsbG8=', $resolver->getClientKey($sender));
    }

    #[Test]
    public function skipsIpWhenWildcard(): void
    {
        $resolver = new NickServClientKeyResolver();
        $sender = new SenderView('UID1', 'N', 'i', 'h', 'cloak', '*', false, false);

        self::assertSame('cloak:cloak', $resolver->getClientKey($sender));
    }

    #[Test]
    public function usesCloakedHostWhenIpEmpty(): void
    {
        $resolver = new NickServClientKeyResolver();
        $sender = new SenderView('UID1', 'N', 'i', 'host', 'cloak', '', false, false);

        self::assertSame('cloak:cloak', $resolver->getClientKey($sender));
    }

    #[Test]
    public function usesHostnameWhenIpAndCloakEmpty(): void
    {
        $resolver = new NickServClientKeyResolver();
        $sender = new SenderView('UID1', 'N', 'i', 'host.example.com', '', '', false, false);

        self::assertSame('host:host.example.com', $resolver->getClientKey($sender));
    }

    #[Test]
    public function fallsBackToUid(): void
    {
        $resolver = new NickServClientKeyResolver();
        $sender = new SenderView('UID123', 'N', 'i', '', '', '', false, false);

        self::assertSame('uid:UID123', $resolver->getClientKey($sender));
    }
}
