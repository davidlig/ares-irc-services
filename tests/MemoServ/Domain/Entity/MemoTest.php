<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Domain\Entity;

use App\MemoServ\Domain\Entity\Memo;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function strlen;

#[CoversClass(Memo::class)]
final class MemoTest extends TestCase
{
    #[Test]
    public function constructorWithTargetNick(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-07 10:00:00 UTC');
        $memo = new Memo(10, null, 5, 'Hello', $createdAt);

        self::assertSame(10, $memo->getTargetNickId());
        self::assertNull($memo->getTargetChannelId());
        self::assertSame(5, $memo->getSenderNickId());
        self::assertSame('Hello', $memo->getMessage());
        self::assertSame($createdAt, $memo->getCreatedAt());
        self::assertFalse($memo->isRead());
        self::assertNull($memo->getReadAt());
    }

    #[Test]
    public function constructorWithTargetChannel(): void
    {
        $memo = new Memo(null, 20, 5, 'Hi channel', new DateTimeImmutable());

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

        new Memo(10, 20, 5, 'msg', new DateTimeImmutable());
    }

    #[Test]
    public function constructorThrowsWhenNeitherTargetSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exactly one of targetNickId or targetChannelId must be set');

        new Memo(null, null, 5, 'msg', new DateTimeImmutable());
    }

    #[Test]
    public function constructorAcceptsCustomCreatedAt(): void
    {
        $created = new DateTimeImmutable('-1 day');
        $memo = new Memo(1, null, 2, 'msg', $created);

        self::assertSame($created, $memo->getCreatedAt());
    }

    #[Test]
    public function markAsReadSetsReadAt(): void
    {
        $memo = new Memo(1, null, 2, 'msg', new DateTimeImmutable());
        self::assertFalse($memo->isRead());

        $at = new DateTimeImmutable();
        $memo->markAsRead($at);

        self::assertTrue($memo->isRead());
        self::assertSame($at, $memo->getReadAt());
    }

    #[Test]
    public function constructorThrowsWhenMessageTooLong(): void
    {
        $long = str_repeat('a', Memo::MESSAGE_MAX_LENGTH + 1);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message cannot exceed');

        new Memo(1, null, 2, $long, new DateTimeImmutable());
    }

    #[Test]
    public function constructorAcceptsMaxLenMessage(): void
    {
        $msg = str_repeat('x', Memo::MESSAGE_MAX_LENGTH);
        $memo = new Memo(1, null, 2, $msg, new DateTimeImmutable());

        self::assertSame(strlen($msg), strlen($memo->getMessage()));
    }

    #[Test]
    public function getIdReturnsReflectedId(): void
    {
        $memo = new Memo(1, null, 2, 'msg', new DateTimeImmutable());
        $prop = new ReflectionProperty(Memo::class, 'id');
        $prop->setValue($memo, 42);

        self::assertSame(42, $memo->getId());
    }
}
