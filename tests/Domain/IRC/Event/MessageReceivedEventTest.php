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
}
