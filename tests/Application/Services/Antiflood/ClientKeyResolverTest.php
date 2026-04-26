<?php

declare(strict_types=1);

namespace App\Tests\Application\Services\Antiflood;

use App\Application\Port\SenderView;
use App\Application\Services\Antiflood\ClientKeyResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClientKeyResolver::class)]
final class ClientKeyResolverTest extends TestCase
{
    #[Test]
    public function resolvesToIpWhenIpBase64IsSet(): void
    {
        $resolver = new ClientKeyResolver();
        $sender = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: 'AQID',
        );

        self::assertSame('ip:AQID', $resolver->getClientKey($sender));
    }

    #[Test]
    public function resolvesToIpWhenIpBase64IsNotAsterisk(): void
    {
        $resolver = new ClientKeyResolver();
        $sender = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: 'ABCDE',
        );

        self::assertSame('ip:ABCDE', $resolver->getClientKey($sender));
    }

    #[Test]
    public function skipsIpWhenAsterisk(): void
    {
        $resolver = new ClientKeyResolver();
        $sender = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: '*',
        );

        self::assertSame('cloak:cloak.example.com', $resolver->getClientKey($sender));
    }

    #[Test]
    public function skipsIpWhenEmptyAndFallsBackToCloakedHost(): void
    {
        $resolver = new ClientKeyResolver();
        $sender = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: '',
        );

        self::assertSame('cloak:cloak.example.com', $resolver->getClientKey($sender));
    }

    #[Test]
    public function fallsBackToHostnameWhenCloakedHostIsEmpty(): void
    {
        $resolver = new ClientKeyResolver();
        $sender = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: '',
            ipBase64: '',
        );

        self::assertSame('host:host.example.com', $resolver->getClientKey($sender));
    }

    #[Test]
    public function fallsBackToUidWhenHostnameIsEmpty(): void
    {
        $resolver = new ClientKeyResolver();
        $sender = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: '',
            cloakedHost: '',
            ipBase64: '',
        );

        self::assertSame('uid:002AAAAAB', $resolver->getClientKey($sender));
    }

    #[Test]
    public function prefersIpOverCloakedHost(): void
    {
        $resolver = new ClientKeyResolver();
        $senderWithIp = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: 'AQID',
        );
        $senderWithoutIp = new SenderView(
            uid: '002AAAAAB',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: '',
        );

        self::assertSame('ip:AQID', $resolver->getClientKey($senderWithIp));
        self::assertSame('cloak:cloak.example.com', $resolver->getClientKey($senderWithoutIp));
    }
}
