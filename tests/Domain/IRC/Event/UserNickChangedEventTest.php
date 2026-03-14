<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserNickChangedEvent::class)]
final class UserNickChangedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $uid = new Uid('AAA111');
        $oldNick = new Nick('OldNick');
        $newNick = new Nick('NewNick');
        $event = new UserNickChangedEvent($uid, $oldNick, $newNick);

        self::assertSame($uid, $event->uid);
        self::assertSame($oldNick, $event->oldNick);
        self::assertSame($newNick, $event->newNick);
    }
}
