<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\BanMaskMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BanMaskMatcher::class)]
final class BanMaskMatcherTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function masks(): iterable
    {
        yield 'host wildcard' => ['*!*@host.example', 'nick', 'ident', 'host.example', true];
        yield 'host wildcard ignores nick and ident' => ['*!*@host.example', 'other', 'someone', 'host.example', true];
        yield 'nick wildcard' => ['nick!*@*', 'nick', 'ident', 'host.example', true];
        yield 'ident wildcard' => ['*!ident@*', 'nick', 'ident', 'host.example', true];
        yield 'subdomain wildcard' => ['*!*@*.domain.example', 'nick', 'ident', 'cloak.domain.example', true];
        yield 'subdomain wildcard rejects bare domain' => ['*!*@*.domain.example', 'nick', 'ident', 'domain.example', false];
        yield 'exact mask' => ['nick!ident@host.example', 'nick', 'ident', 'host.example', true];
        yield 'exact mask with mixed case' => ['NiCk!IdEnT@Host.Example', 'nick', 'ident', 'host.example', true];
        yield 'identity compared with mixed case' => ['nick!ident@host.example', 'NICK', 'IDENT', 'HOST.EXAMPLE', true];
        yield 'different host does not match' => ['*!*@good.example', 'nick', 'ident', 'bad.example', false];
        yield 'different nick does not match' => ['nick!*@*', 'other', 'ident', 'host.example', false];
        yield 'single character wildcard' => ['nic?!ident@host.example', 'nick', 'ident', 'host.example', true];
        yield 'single character wildcard requires exactly one character' => ['nic?!ident@host.example', 'nic', 'ident', 'host.example', false];
        yield 'regex metacharacters are literal' => ['*!*@(evil).example', 'nick', 'ident', '(evil).example', true];
        yield 'regex metacharacters do not behave as a group' => ['*!*@(evil).example', 'nick', 'ident', 'evil.example', false];
        yield 'dot is not a wildcard' => ['*!*@hostxexample', 'nick', 'ident', 'host.example', false];
    }

    #[Test]
    #[DataProvider('masks')]
    public function matchesComparesTheMaskAgainstTheFullIdentity(string $mask, string $nick, string $ident, string $host, bool $expected): void
    {
        self::assertSame($expected, new BanMaskMatcher()->matches($mask, $nick, $ident, $host));
    }
}
