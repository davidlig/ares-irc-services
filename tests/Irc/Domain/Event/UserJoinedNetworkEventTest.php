<?php

declare(strict_types=1);

namespace App\Tests\Irc\Domain\Event;

use App\Irc\Domain\Event\UserJoinedNetworkEvent;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserJoinedNetworkEvent::class)]
final class UserJoinedNetworkEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $uid = new Uid('AAA111');
        $nick = new Nick('TestNick');
        $ident = new Ident('ident');
        $user = new NetworkUser(
            $uid,
            $nick,
            $ident,
            'host.example.com',
            'cloak.example.com',
            '',
            '',
            new DateTimeImmutable(),
            'Real Name',
            '001',
            'aGVsbG8=',
        );
        $event = new UserJoinedNetworkEvent($user);

        self::assertSame($user, $event->user);
    }
}
