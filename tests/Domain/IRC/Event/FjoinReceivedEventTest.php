<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\FjoinReceivedEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FjoinReceivedEvent::class)]
final class FjoinReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $channelName = new ChannelName('#test');
        $uid = new Uid('AAA111');
        $members = [0 => ['uid' => $uid, 'role' => ChannelMemberRole::Op]];
        $event = new FjoinReceivedEvent($channelName, 1234567890, '+nt', $members);

        self::assertSame($channelName, $event->channelName);
        self::assertSame(1234567890, $event->timestamp);
        self::assertSame('+nt', $event->modeStr);
        self::assertSame($members, $event->members);
        self::assertSame([], $event->listModes);
        self::assertSame([], $event->modeParams);
    }
}
