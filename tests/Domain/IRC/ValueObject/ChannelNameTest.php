<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\ValueObject;

use App\Domain\IRC\ValueObject\ChannelName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelName::class)]
final class ChannelNameTest extends TestCase
{
    #[Test]
    public function validChannelNameIsAccepted(): void
    {
        $name = new ChannelName('#test');

        self::assertSame('#test', $name->value);
        self::assertSame('#test', (string) $name);
    }

    #[Test]
    public function mustStartWithHash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must start with #');

        new ChannelName('test');
    }

    #[Test]
    public function cannotBeJustHash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be just "#"');

        new ChannelName('#');
    }

    #[Test]
    public function invalidCharactersRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contains invalid characters');

        new ChannelName('#chan,nel');
    }

    #[Test]
    public function equalsIsCaseInsensitive(): void
    {
        $a = new ChannelName('#Foo');
        $b = new ChannelName('#foo');

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseWhenDifferent(): void
    {
        $a = new ChannelName('#foo');
        $b = new ChannelName('#bar');

        self::assertFalse($a->equals($b));
    }
}
