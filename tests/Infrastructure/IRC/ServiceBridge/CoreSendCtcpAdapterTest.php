<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Infrastructure\IRC\Runtime\ProtocolRuntimeModuleInterface;
use App\Infrastructure\IRC\ServiceBridge\CoreSendCtcpAdapter;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreSendCtcpAdapter::class)]
final class CoreSendCtcpAdapterTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    private CoreSendCtcpAdapter $adapter;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->adapter = new CoreSendCtcpAdapter($this->connectionHolder);
    }

    #[Test]
    public function sendCtcpReplyDoesNothingWhenNotConnected(): void
    {
        $this->adapter->sendCtcpReply('001NS', '001USER', 'VERSION', 'Test');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function sendCtcpReplyDoesNothingWhenConnectedButNoProtocolModule(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::never())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));

        $this->adapter->sendCtcpReply('001NS', '001USER', 'VERSION', 'Test');
    }

    #[Test]
    public function sendCtcpReplyFormatsAndWritesLine(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine')->with("NOTICE 001USER :\x01VERSION Test Response\x01");
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn("NOTICE 001USER :\x01VERSION Test Response\x01");
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendCtcpReply('001NS', '001USER', 'VERSION', 'Test Response');
    }

    #[Test]
    public function sendCtcpReplyUsesCorrectFormat(): void
    {
        $capturedMessage = null;
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::once())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturnCallback(static function (IRCMessage $message) use (&$capturedMessage): string {
            $capturedMessage = $message;

            return 'formatted';
        });
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendCtcpReply('001NS', '001TARGET', 'PING', '12345');

        self::assertNotNull($capturedMessage);
        self::assertSame('NOTICE', $capturedMessage->command);
        self::assertSame('001NS', $capturedMessage->prefix);
        self::assertSame(['001TARGET'], $capturedMessage->params);
        self::assertSame("\x01PING 12345\x01", $capturedMessage->trailing);
    }
}
