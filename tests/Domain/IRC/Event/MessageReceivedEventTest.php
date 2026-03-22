<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageReceivedEvent::class)]
final class MessageReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $message = new IRCMessage('PRIVMSG', null, ['#chan'], 'hi');
        $event = new MessageReceivedEvent($message);

        self::assertSame($message, $event->message);
        self::assertNotNull($event->occurredAt);
    }

    #[Test]
    public function propagationIsNotStoppedByDefault(): void
    {
        $message = new IRCMessage('PRIVMSG', null, ['#chan'], 'hi');
        $event = new MessageReceivedEvent($message);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function stopPropagationStopsEventPropagation(): void
    {
        $message = new IRCMessage('PRIVMSG', null, ['#chan'], 'hi');
        $event = new MessageReceivedEvent($message);

        $event->stopPropagation();

        self::assertTrue($event->isPropagationStopped());
    }
}
