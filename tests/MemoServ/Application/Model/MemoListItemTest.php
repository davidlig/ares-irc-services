<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\Model;

use App\MemoServ\Application\Model\MemoListItem;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoListItem::class)]
final class MemoListItemTest extends TestCase
{
    #[Test]
    public function createsInstance(): void
    {
        $date = new DateTimeImmutable();
        $item = new MemoListItem(1, 'Bob', $date, 'Hello', false);

        self::assertSame(1, $item->index);
        self::assertSame('Bob', $item->senderDisplay);
        self::assertSame($date, $item->createdAt);
        self::assertSame('Hello', $item->preview);
        self::assertFalse($item->isRead);
    }
}
