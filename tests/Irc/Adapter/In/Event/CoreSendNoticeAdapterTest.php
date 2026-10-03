<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\In\Event\CoreSendNoticeAdapter;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\MessageDirection;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CoreSendNoticeAdapter::class)]
final class CoreSendNoticeAdapterTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    private CoreSendNoticeAdapter $adapter;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->adapter = new CoreSendNoticeAdapter($this->connectionHolder);
    }

    #[Test]
    public function sendMessageDoesNothingWhenNotConnected(): void
    {
        $this->adapter->sendMessage('001NS', '001USER', 'Hi', 'NOTICE');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function sendMessageDoesNothingWhenConnectedButNoProtocolModule(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::never())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        // Do not set protocol module so getProtocolModule() returns null

        $this->adapter->sendMessage('001NS', '001USER', 'Hi', 'NOTICE');
    }

    #[Test]
    public function sendNoticeFormatsAndWritesLineWhenConnectedWithModule(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->with(['NOTICE 001USER :Hi']);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn('NOTICE 001USER :Hi');
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendNotice('001NS', '001USER', 'Hi');
    }

    #[Test]
    public function sendMessageSplitsLinesAndSkipsEmpty(): void
    {
        $lines = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->willReturnCallback(static function (array $batch) use (&$lines): void {
            $lines = $batch;
        });
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturnCallback(static fn (IRCMessage $message): string => 'NOTICE 001USER :' . ($message->trailing ?? ''));
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendMessage('001NS', '001USER', "Line1\n\n \nLine2\n", 'NOTICE');

        self::assertSame(['NOTICE 001USER :Line1', 'NOTICE 001USER : ', 'NOTICE 001USER :Line2'], $lines);
    }

    #[Test]
    public function sendMessagePreservesSourceTargetAndPrivmsgSelection(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->with([':001NS PRIVMSG 001USER :Hi']);
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $handler->expects(self::once())->method('formatMessage')->with(self::callback(static function (IRCMessage $message): bool {
            self::assertSame('PRIVMSG', $message->command);
            self::assertSame('001NS', $message->prefix);
            self::assertSame(['001USER'], $message->params);
            self::assertSame('Hi', $message->trailing);
            self::assertSame(MessageDirection::Outgoing, $message->direction);

            return true;
        }))->willReturn(':001NS PRIVMSG 001USER :Hi');
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendMessage('001NS', '001USER', 'Hi', 'PRIVMSG');
    }

    #[Test]
    public function sendMessagePreservesNoticeSourceAndTarget(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->with([':001NS NOTICE 001USER :Hi']);
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $handler->expects(self::once())->method('formatMessage')->with(self::callback(static function (IRCMessage $message): bool {
            self::assertSame('NOTICE', $message->command);
            self::assertSame('001NS', $message->prefix);
            self::assertSame(['001USER'], $message->params);

            return true;
        }))->willReturn(':001NS NOTICE 001USER :Hi');
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendMessage('001NS', '001USER', 'Hi', 'NOTICE');
    }

    #[Test]
    public function sendMessageDoesNotWriteAnEmptyResponse(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::never())->method('writeLine');
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $handler->expects(self::never())->method('formatMessage');
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendMessage('001NS', '001USER', "\n\n", 'NOTICE');
    }

    #[Test]
    public function sendMessageDoesNotWriteAPartiallyFormattedResponse(): void
    {
        $failure = new RuntimeException('Formatting failed');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::never())->method('writeLine');
        $handler = $this->createMock(ProtocolHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('formatMessage')->willReturnCallback(static function (IRCMessage $message) use ($failure): string {
            if ('SECOND' === $message->trailing) {
                throw $failure;
            }

            return 'NOTICE 001USER :FIRST';
        });
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->connectionHolder->setProtocolModule($module);

        try {
            $this->adapter->sendMessage('001NS', '001USER', "FIRST\nSECOND", 'NOTICE');
            self::fail('Expected formatting failure');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    #[Test]
    public function sendMessagePropagatesTheBatchWriteFailureSynchronously(): void
    {
        $failure = new RuntimeException('Write failed');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->with(['NOTICE 001USER :Hi'])->willThrowException($failure);
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn('NOTICE 001USER :Hi');
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $this->connectionHolder->setProtocolModule($module);

        try {
            $this->adapter->sendNotice('001NS', '001USER', 'Hi');
            self::fail('Expected write failure');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }

    #[Test]
    public function sendNoticeToChannelDoesNothingWhenNotConnected(): void
    {
        $this->adapter->sendNoticeToChannel('001CS', '#test', 'Channel notice');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function sendNoticeToChannelDoesNothingWhenConnectedButNoProtocolModule(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::never())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));

        $this->adapter->sendNoticeToChannel('001CS', '#test', 'Channel notice');
    }

    #[Test]
    public function sendNoticeToChannelFormatsAndWritesLineWhenConnected(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->with('NOTICE #test :Channel notice');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn('NOTICE #test :Channel notice');
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendNoticeToChannel('001CS', '#test', 'Channel notice');
    }

    #[Test]
    public function sendNoticeToChannelSplitsLinesAndSkipsEmpty(): void
    {
        $lines = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::exactly(2))->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturnCallback(static fn (IRCMessage $message): string => 'NOTICE #test :' . ($message->trailing ?? ''));
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendNoticeToChannel('001CS', '#test', "Line1\n\nLine2");

        self::assertSame(['NOTICE #test :Line1', 'NOTICE #test :Line2'], $lines);
    }
}
