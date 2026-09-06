<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageReceivedEvent::class)]
final class MessageReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $before = new DateTimeImmutable();
        $message = new IRCMessage('PRIVMSG', null, ['#chan'], 'hi');
        $event = new MessageReceivedEvent($message);
        $after = new DateTimeImmutable();

        self::assertSame($message, $event->message);
        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }
}
