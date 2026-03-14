<?php

declare(strict_types=1);

namespace App\Tests\Application\Helper;

use App\Application\Helper\SecureToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(SecureToken::class)]
final class SecureTokenTest extends TestCase
{
    #[Test]
    public function hexReturnsRequestedLength(): void
    {
        self::assertSame(32, strlen(SecureToken::hex(32)));
        self::assertSame(16, strlen(SecureToken::hex(16)));
        self::assertSame(1, strlen(SecureToken::hex(1)));
    }

    #[Test]
    public function hexZeroLengthReturnsEmptyString(): void
    {
        self::assertSame('', SecureToken::hex(0));
        self::assertSame(0, strlen(SecureToken::hex(0)));
    }

    #[Test]
    public function hexReturnsHexCharactersOnly(): void
    {
        $token = SecureToken::hex(32);

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $token);
    }

    #[Test]
    public function hexDefaultLengthIs32(): void
    {
        self::assertSame(32, strlen(SecureToken::hex()));
    }

    #[Test]
    public function hexGeneratesDifferentValues(): void
    {
        $a = SecureToken::hex(16);
        $b = SecureToken::hex(16);

        self::assertNotSame($a, $b);
    }
}
