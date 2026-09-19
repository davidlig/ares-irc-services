<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Event\IncomingIrcMessageEvent;
use App\Irc\Adapter\Logging\IRCEventSubscriber;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\UdbInboundFrameLogSubscriber;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(UdbInboundFrameLogSubscriber::class)]
final class UdbInboundFrameLogSubscriberTest extends TestCase
{
    private TestHandler $records;

    private UdbInboundFrameLogSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->records = new TestHandler();
        $this->subscriber = new UdbInboundFrameLogSubscriber(new Logger('test', [$this->records]));
    }

    #[Test]
    public function ignoresNonDbMessagesWithoutStoppingPropagation(): void
    {
        $event = new IncomingIrcMessageEvent(IRCMessage::fromRawLine(':001 PING :token'));

        $this->subscriber->onIncomingMessage($event);

        self::assertSame([], $this->records->getRecords());
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function logsValidDbFramesWithSecretValuesRedacted(): void
    {
        $secret = new IncomingIrcMessageEvent(IRCMessage::fromRawLine(':001 DB 002 PUT 7 N ab12 alice::pass :crypt:$2y$10$hash'));
        $vhost = new IncomingIrcMessageEvent(IRCMessage::fromRawLine(':001 DB 002 PUT 7 N ab12 alice::vhost :a.example'));

        $this->subscriber->onIncomingMessage($secret);
        $this->subscriber->onIncomingMessage($vhost);

        self::assertTrue($secret->isPropagationStopped());
        self::assertTrue($vhost->isPropagationStopped());

        $records = $this->records->getRecords();
        self::assertCount(2, $records);
        self::assertSame('< DB', $records[0]->message);
        self::assertSame(':001 DB 002 PUT 7 N ab12 alice::pass :<redacted>', $records[0]->context['raw']);
        self::assertSame('< DB', $records[1]->message);
        self::assertSame(':001 DB 002 PUT 7 N ab12 alice::vhost :a.example', $records[1]->context['raw']);
    }

    #[Test]
    public function malformedDbFramesAreNotMirroredAndStillStopPropagation(): void
    {
        $event = new IncomingIrcMessageEvent(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'BOGUS']));

        $this->subscriber->onIncomingMessage($event);

        self::assertSame([], $this->records->getRecords());
        self::assertTrue($event->isPropagationStopped());
    }

    #[Test]
    public function dispatcherKeepsDbFramesAwayFromTheGenericSubscriber(): void
    {
        $genericRecords = new TestHandler();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new UdbInboundFrameLogSubscriber(new Logger('udb', [$this->records])));
        $dispatcher->addSubscriber(new IRCEventSubscriber(new Logger('generic', [$genericRecords])));

        $dispatcher->dispatch(new IncomingIrcMessageEvent(IRCMessage::fromRawLine(':001 DB 002 PUT 7 N ab12 alice::pass :crypt:$2y$10$hash')));
        $dispatcher->dispatch(new IncomingIrcMessageEvent(IRCMessage::fromRawLine(':001 PING :token')));

        $udbRecords = $this->records->getRecords();
        self::assertCount(1, $udbRecords);
        self::assertSame('< DB', $udbRecords[0]->message);

        $genericRecordsList = $genericRecords->getRecords();
        self::assertCount(1, $genericRecordsList);
        self::assertSame('< PING', $genericRecordsList[0]->message);
    }
}
