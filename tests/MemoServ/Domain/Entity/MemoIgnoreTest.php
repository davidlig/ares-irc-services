<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Domain\Entity;

use App\MemoServ\Domain\Entity\MemoIgnore;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(MemoIgnore::class)]
final class MemoIgnoreTest extends TestCase
{
    #[Test]
    public function constructorWithTargetNick(): void
    {
        $ignore = new MemoIgnore(10, null, 20);

        self::assertSame(10, $ignore->getTargetNickId());
        self::assertNull($ignore->getTargetChannelId());
        self::assertSame(20, $ignore->getIgnoredNickId());
    }

    #[Test]
    public function constructorWithTargetChannel(): void
    {
        $ignore = new MemoIgnore(null, 5, 20);

        self::assertNull($ignore->getTargetNickId());
        self::assertSame(5, $ignore->getTargetChannelId());
        self::assertSame(20, $ignore->getIgnoredNickId());
    }

    #[Test]
    public function constructorThrowsWhenBothTargetsSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoIgnore(10, 5, 20);
    }

    #[Test]
    public function constructorThrowsWhenNeitherTargetSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new MemoIgnore(null, null, 20);
    }

    #[Test]
    public function getIdReturnsReflectedId(): void
    {
        $ignore = new MemoIgnore(1, null, 2);
        $prop = new ReflectionProperty(MemoIgnore::class, 'id');
        $prop->setValue($ignore, 99);

        self::assertSame(99, $ignore->getId());
    }
}
