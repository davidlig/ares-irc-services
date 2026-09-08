<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\ValueObject;

use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(GlobalMessageMask::class)]
final class GlobalMessageMaskTest extends TestCase
{
    #[Test]
    public function parsesAndRendersValidMask(): void
    {
        $mask = GlobalMessageMask::fromString('Global!ident@host.test');
        self::assertSame('Global', $mask->nickname);
        self::assertSame('ident', $mask->ident);
        self::assertSame('host.test', $mask->vhost);
        self::assertSame('Global!ident@host.test', (string) $mask);
    }

    #[Test]
    #[DataProvider('invalidMasks')]
    public function refusesInvalidMask(string $mask): void
    {
        $this->expectException(ValueError::class);
        GlobalMessageMask::fromString($mask);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidMasks(): iterable
    {
        yield 'format' => ['Global@host.test'];
        yield 'nickname too long' => [str_repeat('n', 31) . '!ident@host.test'];
        yield 'invalid nickname' => ['1Global!ident@host.test'];
        yield 'ident too long' => ['Global!' . str_repeat('i', 21) . '@host.test'];
        yield 'invalid ident' => ['Global!invalid ident@host.test'];
        yield 'vhost too long' => ['Global!ident@' . str_repeat('h', 64)];
        yield 'invalid vhost' => ['Global!ident@-host.test'];
    }
}
