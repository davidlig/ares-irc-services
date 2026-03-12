<?php

declare(strict_types=1);

namespace App\Tests\Domain\MemoServ\Entity;

use App\Domain\MemoServ\Entity\Memo;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Memo::class)]
final class MemoTest extends TestCase
{
    #[Test]
    public function constructorWithTargetNick(): void
    {
        $memo = new Memo(10, null, 5, 'Hello');

        self::assertSame(10, $memo->getTargetNickId());
        self::assertNull($memo->getTargetChannelId());
        self::assertSame(5, $memo->getSenderNickId());
        self::assertSame('Hello', $memo->getMessage());
        self::assertFalse($memo->isRead());
        self::assertNull($memo->getReadAt());
    }

    #[Test]
    public function constructorWithTargetChannel(): void
    {
        $memo = new Memo(null, 20, 5, 'Hi channel');

        self::assertNull($memo->getTargetNickId());
        self::assertSame(20, $memo->getTargetChannelId());
        self::assertSame(5, $memo->getSenderNickId());
        self::assertSame('Hi channel', $memo->getMessage());
    }

    #[Test]
    public function constructorThrowsWhenBothTargetsSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new Memo(10, 20, 5, 'msg');
    }

    #[Test]
    public function constructorThrowsWhenNeitherTargetSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new Memo(null, null, 5, 'msg');
    }

    #[Test]
    public function markAsReadSetsReadAt(): void
    {
        $memo = new Memo(10, null, 5, 'Hello');
        $memo->markAsRead();

        self::assertTrue($memo->isRead());
        self::assertInstanceOf(DateTimeImmutable::class, $memo->getReadAt());
    }

    #[Test]
    public function markAsReadAcceptsExplicitTimestamp(): void
    {
        $memo = new Memo(10, null, 5, 'Hello');
        $at = new DateTimeImmutable('2024-06-01 12:00:00');
        $memo->markAsRead($at);

        self::assertSame($at->getTimestamp(), $memo->getReadAt()?->getTimestamp());
    }
}
