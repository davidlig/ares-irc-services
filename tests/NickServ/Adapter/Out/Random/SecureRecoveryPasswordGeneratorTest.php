<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Random;

use App\NickServ\Adapter\Out\Random\SecureRecoveryPasswordGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(SecureRecoveryPasswordGenerator::class)]
final class SecureRecoveryPasswordGeneratorTest extends TestCase
{
    #[Test]
    public function generatesExactlyTwelveLowercaseHexCharacters(): void
    {
        $password = new SecureRecoveryPasswordGenerator()->generate();
        self::assertSame(12, strlen($password));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/D', $password);
    }
}
