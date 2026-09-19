<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Event;

use App\Irc\Adapter\Event\IncomingIrcMessageEvent;
use App\Irc\Adapter\Protocol\IRCMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IncomingIrcMessageEvent::class)]
final class IncomingIrcMessageEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $message = new IRCMessage('PRIVMSG', null, ['#chan'], 'hi');
        $event = new IncomingIrcMessageEvent($message);

        self::assertSame($message, $event->message);
    }

    #[Test]
    public function propagationIsNotStoppedByDefault(): void
    {
        $event = new IncomingIrcMessageEvent(new IRCMessage('PING'));

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function stopPropagationStopsEventPropagation(): void
    {
        $event = new IncomingIrcMessageEvent(new IRCMessage('PING'));

        $event->stopPropagation();

        self::assertTrue($event->isPropagationStopped());
    }
}
