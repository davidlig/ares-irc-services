<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\ValueObject;

use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use PHPUnit\Framework\Attributes\CoversClass;
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
    public function refusesInvalidMask(): void
    {
        $this->expectException(ValueError::class);
        GlobalMessageMask::fromString('Global!invalid ident@host.test');
    }
}
