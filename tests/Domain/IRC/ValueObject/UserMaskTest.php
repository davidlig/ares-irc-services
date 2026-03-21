<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\UserMask;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserMask::class)]
final class UserMaskTest extends TestCase
{
    #[Test]
    public function fromPartsCreatesValidMask(): void
    {
        $mask = UserMask::fromParts('TestNick', 'testuser', 'example.com');

        self::assertSame('TestNick!testuser@example.com', $mask->value);
    }

    #[Test]
    public function fromStringCreatesValidMask(): void
    {
        $mask = UserMask::fromString('nick!user@host');

        self::assertSame('nick!user@host', $mask->value);
    }

    #[Test]
    public function fromStringWithWildcards(): void
    {
        $mask = UserMask::fromString('*!*@*');

        self::assertSame('*!*@*', $mask->value);
    }

    #[Test]
    public function toStringReturnsValue(): void
    {
        $mask = UserMask::fromParts('MyNick', 'myident', 'myhost');

        self::assertSame('MyNick!myident@myhost', (string) $mask);
    }

    #[Test]
    public function emptyStringThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UserMask cannot be empty.');

        UserMask::fromString('');
    }

    #[Test]
    public function fromPartsWithEmptyNick(): void
    {
        $mask = UserMask::fromParts('', 'user', 'host');

        self::assertSame('!user@host', $mask->value);
    }

    #[Test]
    public function fromPartsWithAllEmptyParts(): void
    {
        $mask = UserMask::fromParts('', '', '');

        self::assertSame('!@', $mask->value);
    }

    #[Test]
    public function valueIsReadOnly(): void
    {
        $mask = UserMask::fromString('nick!user@host');

        self::assertSame('nick!user@host', $mask->value);
    }
}
