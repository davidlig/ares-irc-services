<?php

declare(strict_types=1);

namespace App\Tests\Application\Port;

use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SenderView::class)]
final class SenderViewTest extends TestCase
{
    #[Test]
    public function holdsAllPropertiesWithDefaults(): void
    {
        $view = new SenderView(
            uid: '001ABC',
            nick: 'TestNick',
            ident: 'ident',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: 'base64ip',
        );

        self::assertSame('001ABC', $view->uid);
        self::assertSame('TestNick', $view->nick);
        self::assertSame('ident', $view->ident);
        self::assertSame('host.example.com', $view->hostname);
        self::assertSame('cloak.example.com', $view->cloakedHost);
        self::assertSame('base64ip', $view->ipBase64);
        self::assertFalse($view->isIdentified);
        self::assertFalse($view->isOper);
        self::assertSame('', $view->serverSid);
        self::assertSame('', $view->displayHost);
    }

    #[Test]
    public function holdsOptionalPropertiesWhenProvided(): void
    {
        $view = new SenderView(
            uid: '002',
            nick: 'Op',
            ident: 'op',
            hostname: 'h',
            cloakedHost: 'c',
            ipBase64: 'i',
            isIdentified: true,
            isOper: true,
            serverSid: '001',
            displayHost: 'vhost.example.com',
        );

        self::assertTrue($view->isIdentified);
        self::assertTrue($view->isOper);
        self::assertSame('001', $view->serverSid);
        self::assertSame('vhost.example.com', $view->displayHost);
    }

    #[Test]
    public function toUserMaskReturnsCorrectMask(): void
    {
        $view = new SenderView(
            uid: '001ABC',
            nick: 'TestNick',
            ident: 'testident',
            hostname: 'testhost.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: 'base64ip',
        );

        $mask = $view->toUserMask();

        self::assertSame('TestNick!testident@testhost.example.com', $mask->value);
    }
}
