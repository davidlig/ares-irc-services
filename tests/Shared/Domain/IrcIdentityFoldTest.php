<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\IrcIdentityFold;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcIdentityFold::class)]
final class IrcIdentityFoldTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function identities(): iterable
    {
        yield 'plain ascii' => ['Foo', 'foo'];
        yield 'brackets' => ['[Foo]', '{foo}'];
        yield 'backslash and caret' => ['A\\B^C', 'a|b~c'];
        yield 'already folded' => ['{foo}|~', '{foo}|~'];
        yield 'channel' => ['#[Ares]', '#{ares}'];
        yield 'wildcards untouched' => ['*[X]?', '*{x}?'];
        yield 'non ascii bytes untouched' => ["\xC3\x89t\xC3\xA9", "\xC3\x89t\xC3\xA9"];
    }

    #[Test]
    #[DataProvider('identities')]
    public function foldsWithTheFixedRfc1459Mapping(string $value, string $expected): void
    {
        self::assertSame($expected, IrcIdentityFold::fold($value));
    }

    #[Test]
    public function equivalentIdentitiesShareOneKey(): void
    {
        self::assertSame(IrcIdentityFold::fold('[Foo]\\'), IrcIdentityFold::fold('{fOO}|'));
        self::assertNotSame(IrcIdentityFold::fold('[Foo]'), IrcIdentityFold::fold('(Foo)'));
        self::assertTrue(IrcIdentityFold::equals('[Foo]\\^', '{fOO}|~'));
        self::assertTrue(IrcIdentityFold::equals('Foo', 'foo'));
        self::assertFalse(IrcIdentityFold::equals('Foo', 'Fo0'));
    }
}
