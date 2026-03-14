<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserQuitNetworkEvent::class)]
final class UserQuitNetworkEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $uid = new Uid('AAA111');
        $nick = new Nick('TestNick');
        $event = new UserQuitNetworkEvent($uid, $nick, 'Quit message', 'ident', 'host.example.com');

        self::assertSame($uid, $event->uid);
        self::assertSame($nick, $event->nick);
        self::assertSame('Quit message', $event->reason);
        self::assertSame('ident', $event->ident);
        self::assertSame('host.example.com', $event->displayHost);
    }

    #[Test]
    public function identAndDisplayHostDefaultToEmpty(): void
    {
        $uid = new Uid('AAA111');
        $nick = new Nick('TestNick');
        $event = new UserQuitNetworkEvent($uid, $nick, 'Quit');

        self::assertSame('', $event->ident);
        self::assertSame('', $event->displayHost);
    }
}
