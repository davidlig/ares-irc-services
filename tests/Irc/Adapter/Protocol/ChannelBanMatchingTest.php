<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol;

use App\Irc\Adapter\Protocol\ChannelBanIdentity;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelBanMatcher;
use App\Irc\Adapter\Protocol\IrcMaskPattern;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneChannelBanMatcher;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbChannelBanMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelBanIdentity::class)]
#[CoversClass(IrcMaskPattern::class)]
#[CoversClass(InspIRCdChannelBanMatcher::class)]
#[CoversClass(UnrealStandaloneChannelBanMatcher::class)]
#[CoversClass(UnrealUdbChannelBanMatcher::class)]
final class ChannelBanMatchingTest extends TestCase
{
    /** @return iterable<string, array{string, bool, bool}> */
    public static function masks(): iterable
    {
        yield 'exact visible' => ['Alice!shown@visible.example', true, true];
        yield 'case wildcard' => ['aL?ce!*@*.EXAMPLE', true, true];
        yield 'unrelated' => ['Bob!*@*', false, false];
        yield 'real ident' => ['Alice!real@real.example', true, false];
        yield 'real host' => ['*!shown@real.example', true, true];
        yield 'old cloak' => ['*!shown@cloak.example', false, true];
        yield 'ipv4 literal' => ['*!*@192.0.2.7', true, true];
        yield 'ipv4 glob' => ['*!*@192.0.?.*', true, true];
        yield 'wildcard with cidr' => ['*!*@192.0.2.*/24', false, true];
        yield 'wildcard positive overwide cidr' => ['*!*@192.0.2.*/999', false, true];
        yield 'wildcard zero cidr' => ['*!*@192.0.2.*/0', false, false];
        yield 'real host after cidr parsing' => ['*!*@real.example/24', false, true];
        yield 'ipv4 cidr' => ['*!*@192.0.2.0/24', true, true];
        yield 'outside cidr' => ['*!*@192.0.3.0/24', false, false];
        yield 'prefix boundary' => ['*!*@192.0.2.6/31', true, true];
        yield 'zero prefix' => ['*!*@0.0.0.0/0', true, false];
        yield 'overwide prefix' => ['*!*@192.0.2.7/33', true, false];
        yield 'negative prefix' => ['*!*@192.0.2.7/-1', false, false];
        yield 'invalid network' => ['*!*@invalid/24', false, false];
        yield 'invalid range' => ['*!*@192.0.2.7/no', false, false];
        yield 'family mismatch' => ['*!*@2001:db8::/32', false, false];
        yield 'host only' => ['real.example', false, true];
        yield 'ident host' => ['shown@real.example', false, true];
        yield 'inspircd extban' => ['a:Alice!*@*', false, false];
        yield 'unreal extban' => ['~account:Alice!*@*', false, false];
        yield 'inverted extban' => ['!a:Alice!*@*', false, false];
        yield 'literal brackets' => ['[A]lice!*@*', false, false];
        yield 'literal backslash' => ['Alice\\!*@*', false, false];
        yield 'ident mismatch' => ['*!other@real.example', false, false];
    }

    #[DataProvider('masks')]
    public function testProtocolSemantics(string $mask, bool $insp, bool $unreal): void
    {
        $identity = new ChannelBanIdentity('Alice', 'shown', 'real', 'real.example', 'visible.example', 'cloak.example', '192.0.2.7');
        self::assertSame($insp, new InspIRCdChannelBanMatcher()->matches($mask, $identity));
        self::assertSame($unreal, new UnrealStandaloneChannelBanMatcher()->matches($mask, $identity));
        self::assertSame($unreal, new UnrealUdbChannelBanMatcher()->matches($mask, $identity));
    }

    public function testIpv6AndUnknownIp(): void
    {
        foreach ([new InspIRCdChannelBanMatcher(), new UnrealStandaloneChannelBanMatcher(), new UnrealUdbChannelBanMatcher()] as $matcher) {
            $identity = new ChannelBanIdentity('Alice', '~ident', 'real', 'real.example', 'visible.example', 'cloak.example', '2001:db8::1');
            self::assertTrue($matcher->matches('*!~ident@2001:0db8:0:0:0:0:0:1', $identity));
            self::assertTrue($matcher->matches('*!*@2001:db8::/32', $identity));
            self::assertSame(!$matcher instanceof InspIRCdChannelBanMatcher, $matcher->matches('*!*@2001:db8:*/64', $identity));
            self::assertFalse($matcher->matches('*!*@2001:db9::/32', $identity));
            $unknown = new ChannelBanIdentity('Alice', 'ident', 'real', 'host', 'host', 'cloak', null);
            self::assertFalse($matcher->matches('*!*@192.0.2.0/24', $unknown));
        }
    }

    public function testIrcGlobLiteralsAndCasemapping(): void
    {
        self::assertTrue(IrcMaskPattern::matches('[Nick]\\^', '[nick]\\^'));
        self::assertFalse(IrcMaskPattern::matches('[Nick]', '{nick}'));
        self::assertTrue(IrcMaskPattern::matches('[Nick]\\^', '{nick}|~', 'rfc1459'));
        self::assertFalse(IrcMaskPattern::matches('^', '~', 'strict-rfc1459'));
        self::assertTrue(IrcMaskPattern::matches('[Nick]', '{nick}', 'strict-rfc1459'));
    }
}
