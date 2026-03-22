<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Shared;

use App\Infrastructure\Shared\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version::class)]
final class VersionTest extends TestCase
{
    #[Test]
    public function servicesVersionIsNotEmpty(): void
    {
        self::assertNotEmpty(Version::SERVICES);
    }

    #[Test]
    public function servicesVersionStartsWithV(): void
    {
        self::assertStringStartsWith('v', Version::SERVICES);
    }
}
