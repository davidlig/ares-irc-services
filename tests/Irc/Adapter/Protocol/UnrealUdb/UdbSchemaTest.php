<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbSchema::class)]
final class UdbSchemaTest extends TestCase
{
    #[Test]
    public function emptyComponentListsAreRejected(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, [], 'value'));
    }

    #[Test]
    public function pathsDeeperThanThreeComponentsAreRejected(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, ['nick', 'pass', 'x', 'y'], 'value'));
    }

    #[Test]
    public function emptyValuesAreRejected(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, ['nick'], ''));
    }

    #[Test]
    public function oversizedValuesAreRejected(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, ['nick'], str_repeat('x', 4097)));
    }

    #[Test]
    public function valuesWithLineBreaksAreRejected(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, ['nick'], "bad\r\nvalue"));
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, ['nick'], "bad\nvalue"));
    }

    // ---------- Block N ----------

    #[Test]
    #[DataProvider('nickRootProvider')]
    public function nickRootContainersAreValidated(string $nick, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Nicks, [$nick], 'value'));
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function nickRootProvider(): iterable
    {
        yield 'simple nick' => ['david', true];
        yield 'special chars' => ['david|lig', true];
        yield 'brackets' => ['[nick]', true];
        yield 'caret backtick underscore' => ['^a_b', true];
        yield 'braces backslash' => ['{a}\\b', true];
        yield 'leading digit' => ['9nick', false];
        yield 'leading dash' => ['-nick', false];
        yield 'space inside' => ['ni ck', false];
        yield 'thirty one chars' => [str_repeat('a', 31), false];
        yield 'thirty chars' => [str_repeat('a', 30), true];
    }

    #[Test]
    #[DataProvider('nickSubkeyProvider')]
    public function nickSubkeysAreValidated(string $key, string $value, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Nicks, ['david', $key], $value));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function nickSubkeyProvider(): iterable
    {
        yield 'access string' => ['access', '1.2.3.4', true];
        yield 'access star rejected' => ['access', '*1', false];
        yield 'forbid string' => ['forbid', 'bad nick', true];
        yield 'suspend string' => ['suspend', 'reason', true];
        yield 'legacy suspended rejected' => ['suspended', 'reason', false];
        yield 'swhois string' => ['swhois', 'line', true];
        yield 'case insensitive key' => ['VHOST', 'vhost.example.net', true];
        yield 'argon2 password' => ['pass', 'argon2id:$argon2id$hash', true];
        yield 'crypt password' => ['pass', 'crypt:ab$cd', true];
        yield 'empty crypt password' => ['pass', 'crypt:', false];
        yield 'sha256 password' => ['pass', 'sha256:' . str_repeat('a', 64), true];
        yield 'sha256 short' => ['pass', 'sha256:' . str_repeat('a', 63), false];
        yield 'sha256 non hex' => ['pass', 'sha256:' . str_repeat('g', 64), false];
        yield 'unknown hash form' => ['pass', 'md5:abc', false];
        yield 'vhost ok' => ['vhost', 'cloaked.example.net', true];
        yield 'vhost with space' => ['vhost', 'cloaked example', false];
        yield 'vhost with tab' => ['vhost', "cloaked\texample", false];
        yield 'vhost too long' => ['vhost', str_repeat('a', 64), false];
        yield 'vhost max length' => ['vhost', str_repeat('a', 63), true];
        yield 'oper simple' => ['oper', 'netadmin', true];
        yield 'oper mixed' => ['oper', 'Net-Admin_2', true];
        yield 'oper with space' => ['oper', 'net admin', false];
        yield 'oper too long' => ['oper', str_repeat('a', 65), false];
        yield 'challenge removed' => ['challenge', 'sha256', false];
        yield 'modes set' => ['modes', '+BD', true];
        yield 'modes single' => ['modes', 'i', true];
        yield 'modes snomask letter' => ['modes', 's', true];
        yield 'modes oper forbidden' => ['modes', 'o', false];
        yield 'modes unregistered letter' => ['modes', 'X', false];
        yield 'modes signs only' => ['modes', '+-', false];
        yield 'modes too long' => ['modes', '+' . str_repeat('B', 64), false];
        yield 'snomasks ok' => ['snomasks', 'kcfj', true];
        yield 'snomasks signed' => ['snomasks', '+kcfj', true];
        yield 'snomasks digit' => ['snomasks', 'k1', false];
        yield 'snomasks too long' => ['snomasks', str_repeat('k', 65), false];
        yield 'unknown key' => ['unknown', 'value', false];
    }

    #[Test]
    public function nickDepthThreeIsRejected(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, ['david', 'pass', 'extra'], 'value'));
    }

    #[Test]
    public function closedRuntimeModeProfileAcceptsOnlyTheDeclaredCatalog(): void
    {
        self::assertTrue(UdbSchema::validate(UdbBlock::Nicks, ['david', 'modes'], '+' . implode('', UdbSchema::USER_MODES)));
        self::assertFalse(UdbSchema::validate(UdbBlock::Nicks, ['david', 'modes'], '+X'));

        foreach (['q', 'a', 'o', 'h', 'v', 'k', 'l', 'f', 'L', 'F', 'H', 'b', 'e', 'I'] as $mode) {
            self::assertTrue(UdbSchema::validate(UdbBlock::Channels, ['#chan', 'modes'], '+' . $mode . ' value'), $mode);
        }
        foreach (['i', 's', 'p', 'm', 'n', 't', 'c', 'C', 'D', 'G', 'K', 'M', 'N', 'O', 'Q', 'R', 'S', 'T', 'V', 'z', 'Z', 'r', 'P'] as $mode) {
            self::assertTrue(UdbSchema::validate(UdbBlock::Channels, ['#chan', 'modes'], '+' . $mode), $mode);
        }
        self::assertFalse(UdbSchema::validate(UdbBlock::Channels, ['#chan', 'modes'], '+x'));
    }

    #[Test]
    public function numericRecordsAcceptTheFullUnsignedLongRange(): void
    {
        self::assertTrue(UdbSchema::validate(UdbBlock::Channels, ['#chan', 'options'], '*18446744073709551615'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Channels, ['#chan', 'options'], '*18446744073709551616'));
        self::assertSame('*20', UdbSchema::canonicalizeValue('*00020'));
        self::assertSame('*0', UdbSchema::canonicalizeValue('*000'));
        self::assertSame('reason', UdbSchema::canonicalizeValue('reason'));
        self::assertSame('*invalid', UdbSchema::canonicalizeValue('*invalid'));
    }

    // ---------- Block C ----------

    #[Test]
    #[DataProvider('channelRootProvider')]
    public function channelRootContainersAreValidated(string $channel, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Channels, [$channel], 'value'));
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function channelRootProvider(): iterable
    {
        yield 'hash channel' => ['#chan', true];
        yield 'ampersand channel' => ['&local', true];
        yield 'minimum length' => ['#a', true];
        yield 'missing prefix' => ['chan', false];
        yield 'space inside' => ['#ch an', false];
        yield 'colon inside' => ['#ch:an', false];
        yield 'comma inside' => ['#ch,an', false];
        yield 'too long' => ['#' . str_repeat('a', 32), false];
    }

    /** @param list<string> $components */
    #[Test]
    #[DataProvider('channelSubkeyProvider')]
    public function channelSubkeysAreValidated(array $components, string $value, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Channels, $components, $value));
    }

    /** @return iterable<string, array{0: list<string>, 1: string, 2: bool}> */
    public static function channelSubkeyProvider(): iterable
    {
        $chan = '#chan';

        yield 'founder nick' => [[$chan, 'founder'], 'david', true];
        yield 'founder invalid nick' => [[$chan, 'founder'], '9bad', false];
        yield 'modes simple' => [[$chan, 'modes'], '+nt', true];
        yield 'modes with param' => [[$chan, 'modes'], '+k secret', true];
        yield 'modes del with param' => [[$chan, 'modes'], '-k secret', true];
        yield 'modes del without param rejected' => [[$chan, 'modes'], '-k', false];
        yield 'modes limit add' => [[$chan, 'modes'], '+l 50', true];
        yield 'modes limit del' => [[$chan, 'modes'], '-l', true];
        yield 'modes ban add' => [[$chan, 'modes'], '+b *!bad@host', true];
        yield 'modes member add' => [[$chan, 'modes'], '+q david', true];
        yield 'modes extra param rejected' => [[$chan, 'modes'], '+nt 5', false];
        yield 'modes missing param rejected' => [[$chan, 'modes'], '+k', false];
        yield 'modes empty param rejected' => [[$chan, 'modes'], '+k ', false];
        yield 'modes unknown letter' => [[$chan, 'modes'], '+X', false];
        yield 'modes signs only' => [[$chan, 'modes'], '+-', false];
        yield 'modes minus only' => [[$chan, 'modes'], '-nt', true];
        yield 'modes too long' => [[$chan, 'modes'], '+' . str_repeat('n', 512), false];
        yield 'topic string' => [[$chan, 'topic'], 'Welcome here', true];
        yield 'topic star rejected' => [[$chan, 'topic'], '*topic', false];
        yield 'forbid string' => [[$chan, 'forbid'], 'reason', true];
        yield 'suspend string' => [[$chan, 'suspend'], '1', true];
        yield 'legacy suspended rejected' => [[$chan, 'suspended'], '1', false];
        yield 'pass removed' => [[$chan, 'pass'], 'crypt:secret', false];
        yield 'challenge removed' => [[$chan, 'challenge'], 'argon2id', false];
        yield 'options numeric' => [[$chan, 'options'], '*10', true];
        yield 'options missing star' => [[$chan, 'options'], '10', false];
        yield 'options non numeric' => [[$chan, 'options'], '*x', false];
        yield 'unknown key' => [[$chan, 'unknown'], 'value', false];
        yield 'access depth three string' => [[$chan, 'access', 'david'], '5', true];
        yield 'access depth three numeric' => [[$chan, 'access', 'david'], '*5', true];
        yield 'access depth three bad nick' => [[$chan, 'access', '9bad'], '5', false];
        yield 'access depth three star string' => [[$chan, 'access', 'david'], '*level', false];
        yield 'modes depth three rejected' => [[$chan, 'modes', 'david'], 'x', false];
        yield 'case insensitive subkey' => [[$chan, 'FOUNDER'], 'david', true];
    }

    // ---------- Block I ----------

    #[Test]
    #[DataProvider('ipRootProvider')]
    public function ipRootContainersAreValidated(string $mask, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Ips, [$mask], 'value'));
    }

    /** @return iterable<string, array{0: string, 1: bool}> */
    public static function ipRootProvider(): iterable
    {
        yield 'ipv4' => ['1.2.3.4', true];
        yield 'hostname' => ['host.example.net', true];
        yield 'wildcard' => ['192.168.*', true];
        yield 'control byte' => ["ho\x1Fst", false];
        yield 'high byte' => ["host\xC3", false];
        yield 'too long' => [str_repeat('a', 64), false];
    }

    #[Test]
    public function ipSubkeysAreValidated(): void
    {
        self::assertTrue(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'clones'], '*5'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'clones'], '*2147483647'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'clones'], '*2147483648'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'clones'], '5'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'nolines'], 'GZQSTmc'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'nolines'], 'GZ'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'nolines'], 'GX'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'nolines'], str_repeat('G', 17)));
        self::assertTrue(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'host'], 'vhost.example.net'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'host'], 'with space'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'unknown'], 'x'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Ips, ['1.2.3.4', 'clones', 'deep'], '*5'));
    }

    // ---------- Block S ----------

    #[Test]
    #[DataProvider('settingsProvider')]
    public function settingsRecordsAreValidated(string $key, string $value, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Settings, [$key], $value));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function settingsProvider(): iterable
    {
        yield 'clones ok' => ['clones', '*100', true];
        yield 'clones zero' => ['clones', '*0', true];
        yield 'clones overflow' => ['clones', '*2147483648', false];
        yield 'quit ips' => ['quit_ips', 'too many connections', true];
        yield 'quit ips star' => ['quit_ips', '*blocked', false];
        yield 'quit clones' => ['quit_clones', 'clone limit', true];
        yield 'encryption key' => ['encryption_key', str_repeat('a', 64), true];
        yield 'encryption key short' => ['encryption_key', str_repeat('a', 63), false];
        yield 'encryption key non hex' => ['encryption_key', str_repeat('g', 64), false];
        yield 'suffix ok' => ['suffix', '.users.example.net', true];
        yield 'suffix hyphen label' => ['suffix', '.users-2.example.net', true];
        yield 'suffix missing dot' => ['suffix', 'users.example.net', false];
        yield 'suffix leading hyphen' => ['suffix', '.-users', false];
        yield 'suffix trailing hyphen' => ['suffix', '.users-', false];
        yield 'suffix trailing dot' => ['suffix', '.users.', false];
        yield 'suffix only dot' => ['suffix', '.', false];
        yield 'suffix invalid char' => ['suffix', '.us_ers', false];
        yield 'suffix double dot' => ['suffix', '..a', false];
        yield 'suffix too long' => ['suffix', '.' . str_repeat('a', 31), false];
        yield 'suffix max length' => ['suffix', '.' . str_repeat('a', 30), true];
        yield 'nickserv mask' => ['nickserv', 'NickServ!NickServ@services', true];
        yield 'nickserv missing bang' => ['nickserv', 'NickServ@services', false];
        yield 'nickserv leading bang' => ['nickserv', '!ident@host', false];
        yield 'nickserv empty ident' => ['nickserv', 'NickServ!@host', false];
        yield 'nickserv empty host' => ['nickserv', 'NickServ!ident@', false];
        yield 'nickserv with space' => ['nickserv', 'Nick Serv!i@h', false];
        yield 'nickserv dotted host' => ['nickserv', 'NickServ!ident@services.example.net', true];
        yield 'chanserv mask' => ['chanserv', 'ChanServ!ChanServ@services', true];
        yield 'ipserv mask' => ['ipserv', 'IpServ!IpServ@services', true];
        yield 'nickserv unknown form' => ['unknown', 'value', false];
        yield 'flood ok' => ['flood', '5:60', true];
        yield 'flood zero attempts' => ['flood', '0:60', false];
        yield 'flood zero period' => ['flood', '5:0', false];
        yield 'flood single part' => ['flood', '5', false];
        yield 'flood three parts' => ['flood', '5:60:1', false];
        yield 'flood non numeric' => ['flood', 'a:60', false];
        yield 'flood overflow' => ['flood', '2147483648:60', false];
        yield 'propagator single' => ['propagator', 'hub.example.net', true];
        yield 'propagator list' => ['propagator', 'hub1.example.net, hub2.example.net', true];
        yield 'propagator trailing comma' => ['propagator', 'hub1,hub2,', false];
        yield 'propagator leading comma' => ['propagator', ',hub1', false];
        yield 'propagator inner space' => ['propagator', 'hub example.net', false];
        yield 'propagator too long' => ['propagator', str_repeat('a', 64), false];
        yield 'propagator tab rejected' => ['propagator', "hub1\thub2", false];
        yield 'case insensitive key' => ['CLONES', '*5', true];
    }

    #[Test]
    public function settingsRejectsDeeperPaths(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Settings, ['clones', 'x'], '*5'));
    }

    // ---------- Block L ----------

    #[Test]
    public function linksAreValidated(): void
    {
        self::assertTrue(UdbSchema::validate(UdbBlock::Links, ['hub.example.net'], 'value'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Links, ['hub'], 'value'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Links, ['-hub.example'], 'value'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Links, ['hub_underscore'], 'value'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Links, [str_repeat('a', 64)], 'value'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Links, ['hub.example.net', 'options'], '*2'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Links, ['hub.example.net', 'OPTIONS'], '*2'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Links, ['hub.example.net', 'clones'], '*2'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Links, ['hub.example.net'], ''));
        self::assertFalse(UdbSchema::validate(UdbBlock::Links, ['hub.example.net', 'options', 'deep'], '*2'));
    }

    // ---------- Block K ----------

    #[Test]
    public function linesRequireAtLeastTheTypeAndPattern(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['G'], 'reason'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['X', 'mask'], 'reason'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['g', 'mask'], 'reason'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['GG', 'mask'], 'reason'));
    }

    #[Test]
    #[DataProvider('lineMaskProvider')]
    public function lineMasksAreValidated(string $type, string $mask, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Lines, [$type, $mask, 'reason'], 'reason'));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: bool}> */
    public static function lineMaskProvider(): iterable
    {
        yield 'gline user host' => ['G', 'user@host', true];
        yield 'gline host only' => ['G', 'bad.example.net', false];
        yield 'gline empty user' => ['G', '@host', false];
        yield 'gline empty host' => ['G', 'user@', false];
        yield 'gline double at' => ['G', 'user@ho@st', false];
        yield 'gline long user' => ['G', str_repeat('u', 128) . '@host', false];
        yield 'gline long host' => ['G', 'user@' . str_repeat('h', 128), false];
        yield 'gline max user' => ['G', str_repeat('u', 127) . '@host', true];
        yield 'zline mask' => ['Z', '10.0.0.0/8', true];
        yield 'zline canonical address without prefix' => ['Z', '192.0.2.1', true];
        yield 'zline empty address' => ['Z', '/24', false];
        yield 'zline multiple slashes' => ['Z', '192.0.2.0/24/1', false];
        yield 'zline invalid address' => ['Z', '999.0.0.1', false];
        yield 'zline noncanonical address' => ['Z', '2001:0db8::/32', false];
        yield 'zline invalid prefix syntax' => ['Z', '192.0.2.0/01', false];
        yield 'zline prefix too large' => ['Z', '192.0.2.0/33', false];
        yield 'zline host bits set after prefix' => ['Z', '192.0.2.1/24', false];
        yield 'shun mask' => ['S', 'user@host', true];
        yield 'qline nick pattern' => ['Q', 'bad*nick', true];
        yield 'qline length bounded' => ['Q', str_repeat('n', 128), false];
    }

    #[Test]
    public function lineRootValuesRejectStarAndEmpty(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['G', 'user@host'], '*reason'));
    }

    #[Test]
    public function lineSubkeysAreValidated(): void
    {
        self::assertTrue(UdbSchema::validate(UdbBlock::Lines, ['G', 'user@host', 'reason'], 'spam'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['G', 'user@host', 'reason'], '*spam'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Lines, ['G', 'user@host', 'expires'], '*1700000000'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['G', 'user@host', 'expires'], '*0'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['G', 'user@host', 'duration'], '*60'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['G', 'user@host', 'unknown'], 'x'));
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['Q', 'badnick', 'unknown'], 'x'));
        self::assertTrue(UdbSchema::validate(UdbBlock::Lines, ['Q', 'badnick', 'reason'], 'nick ban'));
    }

    #[Test]
    public function spamfilterRequiresDepthThree(): void
    {
        self::assertFalse(UdbSchema::validate(UdbBlock::Lines, ['F', 'pattern'], 'reason'));
    }

    #[Test]
    #[DataProvider('spamfilterProvider')]
    public function spamfilterRecordsAreValidated(string $pattern, string $subkey, string $value, bool $expected): void
    {
        self::assertSame($expected, UdbSchema::validate(UdbBlock::Lines, ['F', $pattern, $subkey], $value));
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: bool}> */
    public static function spamfilterProvider(): iterable
    {
        $b64 = 'b64:' . base64_encode('hello world');

        yield 'match regex' => [$b64, 'match-type', 'regex', true];
        yield 'match simple' => [$b64, 'match-type', 'simple', true];
        yield 'match case sensitive' => [$b64, 'match-type', 'Regex', false];
        yield 'targets canonical and ASCII ascending' => [$b64, 'targets', 'cp', true];
        yield 'targets native order but ASCII descending' => [$b64, 'targets', 'pn', false];
        yield 'targets previously accepted mixed case' => [$b64, 'targets', 'cpNqR', false];
        yield 'targets empty' => [$b64, 'targets', '', false];
        yield 'targets out of order' => [$b64, 'targets', 'pc', false];
        yield 'targets duplicate' => [$b64, 'targets', 'cc', false];
        yield 'action dynamic' => [$b64, 'action', 'GLINE', true];
        yield 'action config only' => [$b64, 'action', 'set', false];
        yield 'ban time positive' => [$b64, 'ban-time', '*3600', true];
        yield 'expires positive' => [$b64, 'expires', '*1700000000', true];
        yield 'zero expiry' => [$b64, 'expires', '*0', false];
        yield 'reason' => [$b64, 'reason', 'spam bot', true];
        yield 'legacy type key' => [$b64, 'type', 'c', false];
        yield 'legacy duration key' => [$b64, 'duration', '*60', false];
        yield 'raw pattern rejected' => ['bad.*word', 'reason', 'raw', false];
        yield 'empty encoded pattern rejected' => ['b64:', 'reason', 'bad', false];
        yield 'misaligned encoded pattern rejected' => ['b64:YQ', 'reason', 'bad', false];
        yield 'invalid base64' => ['b64:!!==', 'reason', 'bad', false];
        yield 'decoded nul rejected' => ['b64:' . base64_encode("a\0b"), 'reason', 'bad', false];
    }

    #[Test]
    public function aggregateValidationCompilesOnlyCompleteRegexSpamfilters(): void
    {
        self::assertTrue(UdbSchema::validateAggregate(UdbBlock::Channels, []));
        self::assertFalse(UdbSchema::validateAggregate(UdbBlock::Lines, ['%41::mask::reason' => 'invalid path']));
        self::assertFalse(UdbSchema::validateAggregate(UdbBlock::Lines, ['G::user@host::duration' => '*60']));

        $invalidRegex = 'b64%3A' . base64_encode('(');
        self::assertTrue(UdbSchema::validateAggregate(UdbBlock::Lines, [
            'F::' . $invalidRegex . '::match-type' => 'regex',
        ]));
        self::assertTrue(UdbSchema::validateAggregate(UdbBlock::Lines, $this->spamfilterProfile($invalidRegex, 'simple')));
        self::assertFalse(UdbSchema::validateAggregate(UdbBlock::Lines, $this->spamfilterProfile($invalidRegex, 'regex')));

        $validRegex = 'b64%3A' . base64_encode('^foo~\\d+$');
        self::assertTrue(UdbSchema::validateAggregate(UdbBlock::Lines, $this->spamfilterProfile($validRegex, 'regex')));
    }

    #[Test]
    public function nickForbidMustBeTheProfilesOnlyLeaf(): void
    {
        self::assertTrue(UdbSchema::validateAggregate(UdbBlock::Nicks, [
            'ForbiddenNick::forbid' => 'reserved',
        ]));
        self::assertFalse(UdbSchema::validateAggregate(UdbBlock::Nicks, [
            'ForbiddenNick::forbid' => 'reserved',
            'forbiddennick::pass' => 'sha256:' . str_repeat('a', 64),
        ]));
        self::assertTrue(UdbSchema::validateAggregate(UdbBlock::Nicks, [
            'NormalNick::pass' => 'sha256:' . str_repeat('a', 64),
            'normalnick::vhost' => 'users.example',
        ]));
    }

    /** @return array<string, string> */
    private function spamfilterProfile(string $pattern, string $matchType): array
    {
        $prefix = 'F::' . $pattern . '::';

        return [
            $prefix . 'match-type' => $matchType,
            $prefix . 'targets' => 'c',
            $prefix . 'action' => 'kill',
            $prefix . 'reason' => 'blocked',
        ];
    }

    // ---------- Secrets ----------

    /** @param list<string> $components */
    #[Test]
    #[DataProvider('secretProvider')]
    public function secretPathsAreDetected(string $block, array $components, bool $expected): void
    {
        $udbBlock = UdbBlock::fromLetter($block);
        self::assertNotNull($udbBlock);
        self::assertSame($expected, UdbSchema::isSecret($udbBlock, $components));
    }

    /** @return iterable<string, array{0: string, 1: list<string>, 2: bool}> */
    public static function secretProvider(): iterable
    {
        yield 'nick pass' => ['N', ['nick', 'pass'], true];
        yield 'nick vhost' => ['N', ['nick', 'vhost'], false];
        yield 'nick root' => ['N', ['nick'], false];
        yield 'channel pass removed' => ['C', ['#chan', 'pass'], false];
        yield 'channel challenge removed' => ['C', ['#chan', 'challenge'], false];
        yield 'channel founder' => ['C', ['#chan', 'founder'], false];
        yield 'encryption key' => ['S', ['encryption_key'], true];
        yield 'nickserv mask' => ['S', ['nickserv'], false];
        yield 'gline' => ['K', ['G', 'user@host'], false];
        yield 'clones' => ['I', ['1.2.3.4', 'clones'], false];
        yield 'options' => ['L', ['hub', 'options'], false];
        yield 'access depth three' => ['C', ['#chan', 'access', 'nick'], false];
    }
}
