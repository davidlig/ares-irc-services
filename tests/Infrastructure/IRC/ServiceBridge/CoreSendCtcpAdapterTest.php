<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ProtocolModuleInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\ServiceBridge\CoreSendCtcpAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

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
        $connection->expects(self::never())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));

        $this->adapter->sendCtcpReply('001NS', '001USER', 'VERSION', 'Test');
    }

    #[Test]
    public function sendCtcpReplyFormatsAndWritesLine(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with("NOTICE 001USER :\x01VERSION Test Response\x01");
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn("NOTICE 001USER :\x01VERSION Test Response\x01");
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendCtcpReply('001NS', '001USER', 'VERSION', 'Test Response');
    }

    #[Test]
    public function sendCtcpReplyUsesCorrectFormat(): void
    {
        $capturedMessage = new stdClass();
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturnCallback(static function ($msg) use (&$capturedMessage) {
            $capturedMessage->value = $msg;

            return 'formatted';
        });
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendCtcpReply('001NS', '001TARGET', 'PING', '12345');

        self::assertSame('NOTICE', $capturedMessage->value->command);
        self::assertSame('001NS', $capturedMessage->value->prefix);
        self::assertSame(['001TARGET'], $capturedMessage->value->params);
        self::assertSame("\x01PING 12345\x01", $capturedMessage->value->trailing);
    }
}
