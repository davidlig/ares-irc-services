<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
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
