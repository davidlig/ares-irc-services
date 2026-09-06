<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Event;

use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserJoinedChannelEvent::class)]
final class UserJoinedChannelEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $uid = new Uid('AAA111');
        $channel = new ChannelName('#test');
        $event = new UserJoinedChannelEvent($uid, $channel, ChannelMemberRole::Voice);

        self::assertSame($uid, $event->uid);
        self::assertSame($channel, $event->channel);
        self::assertSame(ChannelMemberRole::Voice, $event->role);
    }
}
