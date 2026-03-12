<?php

declare(strict_types=1);

namespace App\Tests\Domain\MemoServ\Entity;

use App\Domain\MemoServ\Entity\MemoIgnore;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoIgnore::class)]
final class MemoIgnoreTest extends TestCase
{
    #[Test]
    public function constructorWithTargetNick(): void
    {
        $ignore = new MemoIgnore(10, null, 5);

        self::assertSame(10, $ignore->getTargetNickId());
        self::assertNull($ignore->getTargetChannelId());
        self::assertSame(5, $ignore->getIgnoredNickId());
    }

    #[Test]
    public function constructorWithTargetChannel(): void
    {
        $ignore = new MemoIgnore(null, 20, 5);

        self::assertNull($ignore->getTargetNickId());
        self::assertSame(20, $ignore->getTargetChannelId());
        self::assertSame(5, $ignore->getIgnoredNickId());
    }

    #[Test]
    public function constructorThrowsWhenBothSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoIgnore(10, 20, 5);
    }

    #[Test]
    public function constructorThrowsWhenNeitherSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoIgnore(null, null, 5);
    }
}
