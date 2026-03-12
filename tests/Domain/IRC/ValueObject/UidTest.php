<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\Uid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Uid::class)]
final class UidTest extends TestCase
{
    #[Test]
    public function validUidIsAccepted(): void
    {
        $uid = new Uid('AAA111');

        self::assertSame('AAA111', $uid->value);
        self::assertSame('AAA111', (string) $uid);
    }

    #[Test]
    public function emptyUidThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UID cannot be empty.');

        new Uid('');
    }

    #[Test]
    public function tooLongUidThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UID must not exceed');

        new Uid(str_repeat('x', 129));
    }

    #[Test]
    public function controlCharsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UID must not contain control characters.');

        new Uid("abc\n");
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        $a = new Uid('AAA111');
        $b = new Uid('AAA111');
        $c = new Uid('BBB222');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}

