<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Random;

use App\NickServ\Adapter\Out\Random\SecureVerificationTokenGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecureVerificationTokenGenerator::class)]
final class SecureVerificationTokenGeneratorTest extends TestCase
{
    #[Test]
    public function generatesIndependentThirtyTwoCharacterHexTokens(): void
    {
        $generator = new SecureVerificationTokenGenerator();
        $first = $generator->generate();
        $second = $generator->generate();

        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\z/', $first);
        self::assertNotSame($first, $second);
    }
}
