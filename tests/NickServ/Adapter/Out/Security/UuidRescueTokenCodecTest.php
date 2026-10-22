<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security;

use App\NickServ\Adapter\Out\Security\UuidRescueTokenCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UuidRescueTokenCodec::class)]
final class UuidRescueTokenCodecTest extends TestCase
{
    public function testCanonicalGenerationAndVerification(): void
    {
        $codec = new UuidRescueTokenCodec();
        $token = $codec->generate();
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $token);
        self::assertNotSame($token, $codec->generate());
        $hash = $codec->hash($token);
        self::assertSame(hash('sha256', $token), $hash);
        self::assertTrue($codec->matches(' ' . strtoupper($token) . ' ', $hash));
        self::assertFalse($codec->matches($codec->generate(), $hash));
        self::assertNull($codec->hash('bad'));
        self::assertNull($codec->hash('12345678-1234-1234-8234-123456789abc'));
        self::assertFalse($codec->matches('bad', $hash));
    }
}
