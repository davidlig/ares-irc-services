<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\Nick;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Nick::class)]
final class NickTest extends TestCase
{
    #[Test]
    public function validNickIsAccepted(): void
    {
        $nick = new Nick('TestNick');

        self::assertSame('TestNick', $nick->value);
        self::assertSame('TestNick', (string) $nick);
    }

    #[Test]
    public function emptyNickThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nick cannot be empty.');

        new Nick('');
    }

    #[Test]
    public function invalidNickThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"!bad nick" is not a valid IRC nickname.');

        new Nick('!bad nick');
    }

    #[Test]
    public function equalsIsCaseInsensitive(): void
    {
        $a = new Nick('FooBar');
        $b = new Nick('foobar');

        self::assertTrue($a->equals($b));
        self::assertTrue($b->equals($a));
    }
}
