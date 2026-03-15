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
    public function getSubscribedEventsReturnsBurstCompleteWithPriority(): void
    {
        self::assertSame(
            [NetworkBurstCompleteEvent::class => ['onBurstComplete', 94]],
            MemoServBot::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onBurstCompleteWritesIntroductionLineWhenModulePresent(): void
    {
        $introLine = ':001 UID MemoServ MemoServ 0 0 services.example.com 001MS 0 * Memo Service';
        $connection = $this->createMock(ConnectionInterface::class);
        $formatter = $this->createMock(ServiceIntroductionFormatterInterface::class);
        $formatter->expects(self::atLeastOnce())->method('formatIntroduction')->with(
            '001',
            'MemoServ',
            'MemoServ',
            self::HOSTNAME,
            self::MEMOSERV_UID,
            'Memo Service',
        )->willReturn($introLine);
        $module = $this->createStub(ProtocolModuleInterface::class);
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

    #[Test]
    public function getNickAndGetUidReturnConfiguredValues(): void
    {
        self::assertSame('MemoServ', $this->bot->getNick());
        self::assertSame(self::MEMOSERV_UID, $this->bot->getUid());
    }

    #[Test]
    public function sendNoticeDoesNotWriteWhenNotConnected(): void
    {
        $this->bot->sendNotice('001USER', 'Hi');
        self::assertFalse($this->connectionHolder->isConnected());
    }

    #[Test]
    public function sendMessageDoesNotWriteWhenModuleNotSet(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->bot->sendMessage('001U', 'Line', 'NOTICE');
    }

    #[Test]
    public function sendMessageWithPrivmsgFormatsAsPrivmsg(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $lines = [];
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn('PRIVMSG 001U :Hi');
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);
        $this->bot->sendMessage('001U', 'Hi', 'PRIVMSG');
        self::assertCount(1, $lines);
        self::assertSame('PRIVMSG 001U :Hi', $lines[0]);
    }

    #[Test]
    public function sendMessageSplitsLinesAndSkipsEmpty(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $lines = [];
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturnCallback(static fn (): string => 'NOTICE 001U :x');
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);
        $this->bot->sendMessage('001U', "First\n\nSecond", 'NOTICE');
        self::assertCount(2, $lines);
    }

    #[Test]
    public function sendMessageDefaultsUnknownMessageTypeToNotice(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $lines = [];
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $handler->expects(self::once())->method('formatMessage')
            ->with(self::callback(static fn ($msg): bool => 'NOTICE' === $msg->command));
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);
        $this->bot->sendMessage('001U', 'Test', 'UNKNOWN_TYPE');
    }

    #[Test]
    public function onBurstCompleteLogsIntroduction(): void
    {
        $introLine = ':001 UID MemoServ MemoServ 0 0 services.example.com 001MS 0 * Memo Service';
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with($introLine);
        $formatter = $this->createMock(ServiceIntroductionFormatterInterface::class);
        $formatter->expects(self::atLeastOnce())->method('formatIntroduction')->willReturn($introLine);
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getIntroductionFormatter')->willReturn($formatter);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with('MemoServ introduced to network.', [
                'uid' => self::MEMOSERV_UID,
                'nick' => 'MemoServ',
            ]);

        $bot = new MemoServBot(
            $this->connectionHolder,
            self::HOSTNAME,
            self::MEMOSERV_UID,
            'MemoServ',
            'MemoServ',
            'Memo Service',
            $logger,
        );

        $this->connectionHolder->setProtocolModule($module);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $bot->onBurstComplete($event);
    }

    private function createModuleWithHandlerThatReturnsLine(string $line): ProtocolModuleInterface
    {
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn($line);
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);

        return $module;
    }
}
