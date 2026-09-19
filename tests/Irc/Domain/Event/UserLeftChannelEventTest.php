<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Event;

use App\Irc\Domain\Event\UserLeftChannelEvent;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserLeftChannelEvent::class)]
final class UserLeftChannelEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $uid = new Uid('AAA111');
        $nick = new Nick('TestNick');
        $channel = new ChannelName('#test');
        $event = new UserLeftChannelEvent($uid, $nick, $channel, 'leaving', false);

        self::assertSame($uid, $event->uid);
        self::assertSame($nick, $event->nick);
        self::assertSame($channel, $event->channel);
        self::assertSame('leaving', $event->reason);
        self::assertFalse($event->wasKicked);
    }
}
