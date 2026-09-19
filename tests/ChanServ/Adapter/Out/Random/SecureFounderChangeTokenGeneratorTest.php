<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Random;

use App\ChanServ\Adapter\Out\Random\SecureFounderChangeTokenGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(SecureFounderChangeTokenGenerator::class)]
final class SecureFounderChangeTokenGeneratorTest extends TestCase
{
    #[Test]
    public function generateReturns32HexCharacters(): void
    {
        $generator = new SecureFounderChangeTokenGenerator();
        $token = $generator->generate();

        self::assertSame(32, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
    }
}
