<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemoServ\Bot;

use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\MemoServ\Bot\MemoServBot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoServBot::class)]
final class MemoServBotTest extends TestCase
{
    private const MEMOSERV_UID = '001MS';
    private const HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private MemoServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();

        $this->bot = new MemoServBot(
            $this->connectionHolder,
            self::HOSTNAME,
            self::MEMOSERV_UID,
        );
    }

    #[Test]
    public function getSubscribedEvents_returns_burst_complete_with_priority(): void
    {
        self::assertSame(
            [NetworkBurstCompleteEvent::class => ['onBurstComplete', 94]],
            MemoServBot::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onBurstComplete_writes_introduction_line_when_module_present(): void
    {
        $introLine = ':001 UID MemoServ MemoServ 0 0 services.example.com 001MS 0 * Memo Service';
        $connection = $this->createMock(ConnectionInterface::class);
        $formatter = $this->createMock(ServiceIntroductionFormatterInterface::class);
        $formatter->method('formatIntroduction')->with(
            '001',
            'MemoServ',
            'MemoServ',
            self::HOSTNAME,
            self::MEMOSERV_UID,
            'Memo Service',
        )->willReturn($introLine);
        $module = $this->createMock(ProtocolModuleInterface::class);
        $module->method('getIntroductionFormatter')->willReturn($formatter);

        $this->connectionHolder->setProtocolModule($module);
        $connection->expects(self::once())->method('writeLine')->with($introLine);

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->bot->onBurstComplete($event);
    }

    #[Test]
    public function onBurstComplete_does_not_write_when_module_null(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->bot->onBurstComplete($event);
    }

    #[Test]
    public function sendNotice_delegates_to_connection_when_connected_with_module(): void
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
