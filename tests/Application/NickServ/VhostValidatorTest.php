<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\VhostValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VhostValidator::class)]
final class VhostValidatorTest extends TestCase
{
    #[Test]
    public function normalizeReturnsNullForNull(): void
    {
        $v = new VhostValidator();

        self::assertNull($v->normalize(null));
    }

    #[Test]
    public function normalizeReturnsNullForEmptyOrWhitespace(): void
    {
        $v = new VhostValidator();

        self::assertNull($v->normalize(''));
        self::assertNull($v->normalize('   '));
    }

    #[Test]
    public function normalizeReturnsValueWhenValid(): void
    {
        $v = new VhostValidator();

        self::assertSame('valid', $v->normalize('valid'));
        self::assertSame('my-vhost', $v->normalize('  my-vhost  '));
        self::assertSame('a.b', $v->normalize('a.b'));
    }

    #[Test]
    public function normalizeReturnsNullWhenTooLong(): void
    {
        $v = new VhostValidator();
        $long = str_repeat('a', VhostValidator::MAX_LENGTH + 1);

        self::assertNull($v->normalize($long));
    }

    #[Test]
    public function normalizeAcceptsMaxLengthBoundary(): void
    {
        $v = new VhostValidator();
        $exactlyMax = str_repeat('a', VhostValidator::MAX_LENGTH);

        self::assertSame($exactlyMax, $v->normalize($exactlyMax));
    }

    #[Test]
    public function normalizeReturnsNullWhenInvalidChars(): void
    {
        $v = new VhostValidator();

        self::assertNull($v->normalize('-leading'));
        self::assertNull($v->normalize('trailing-'));
        self::assertNull($v->normalize('under_score'));
    }

    #[Test]
    public function isValidReturnsTrueWhenNormalizeSucceeds(): void
    {
        $v = new VhostValidator();

        self::assertTrue($v->isValid('valid'));
    }

    #[Test]
    public function isValidReturnsFalseWhenNormalizeReturnsNull(): void
    {
        $v = new VhostValidator();

        self::assertFalse($v->isValid(null));
        self::assertFalse($v->isValid(''));
        self::assertFalse($v->isValid('invalid!'));
    }
}
