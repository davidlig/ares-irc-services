<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Bot;

use App\Application\Port\ApplyOutgoingChannelModesPort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\ChanServ\Bot\ChanServBot;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServBot::class)]
final class ChanServBotTest extends TestCase
{
    private const CHANSERV_UID = '001CS';

    private const HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private ChanServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $applyOutgoingChannelModes = $this->createStub(ApplyOutgoingChannelModesPort::class);

        $this->bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $applyOutgoingChannelModes,
            self::HOSTNAME,
            self::CHANSERV_UID,
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsBurstCompleteWithPriority(): void
    {
        self::assertSame(
            [NetworkBurstCompleteEvent::class => ['onBurstComplete', 95]],
            ChanServBot::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onBurstCompleteWritesIntroductionLineWhenModulePresent(): void
    {
        $introLine = ':001 UID ChanServ ChanServ 0 0 services.example.com 001CS 0 * Channel Registration Services';
        $connection = $this->createMock(ConnectionInterface::class);
        $formatter = $this->createMock(ServiceIntroductionFormatterInterface::class);
        $formatter->method('formatIntroduction')->with(
            '001',
            'ChanServ',
            'ChanServ',
            self::HOSTNAME,
            self::CHANSERV_UID,
            'Channel Registration Services',
        )->willReturn($introLine);
        $module = $this->createMock(ProtocolModuleInterface::class);
        $module->method('getIntroductionFormatter')->willReturn($formatter);

        $this->connectionHolder->setProtocolModule($module);
        $connection->expects(self::once())->method('writeLine')->with($introLine);

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->bot->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNotWriteWhenModuleNull(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->bot->onBurstComplete($event);
    }

    #[Test]
    public function sendNoticeDelegatesToConnectionWhenConnectedWithModule(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->with(self::anything());
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE 001USER :Hi'));

        $this->bot->sendNotice('001USER', 'Hi');
    }

    private function createModuleWithHandlerThatReturnsLine(string $line): ProtocolModuleInterface
    {
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn($line);
        $module = $this->createMock(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);

        return $module;
    }
}
