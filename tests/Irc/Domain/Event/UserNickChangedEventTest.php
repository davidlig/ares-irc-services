<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Event;

use App\Irc\Domain\Event\UserNickChangedEvent;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
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
