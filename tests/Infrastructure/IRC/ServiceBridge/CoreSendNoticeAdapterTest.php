<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ProtocolModuleInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\ServiceBridge\CoreSendNoticeAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreSendNoticeAdapter::class)]
final class CoreSendNoticeAdapterTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    private CoreSendNoticeAdapter $adapter;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->adapter = new CoreSendNoticeAdapter($this->connectionHolder, '001NS');
    }

    #[Test]
    public function sendMessageDoesNothingWhenNotConnected(): void
    {
        $this->adapter->sendMessage('001USER', 'Hi', 'NOTICE');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function sendMessageDoesNothingWhenConnectedButNoProtocolModule(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        // Do not set protocol module so getProtocolModule() returns null

        $this->adapter->sendMessage('001USER', 'Hi', 'NOTICE');
    }

    #[Test]
    public function sendNoticeFormatsAndWritesLineWhenConnectedWithModule(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with('NOTICE 001USER :Hi');
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn('NOTICE 001USER :Hi');
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendNotice('001USER', 'Hi');
    }

    #[Test]
    public function sendMessageSplitsLinesAndSkipsEmpty(): void
    {
        $lines = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(2))->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturnCallback(static fn ($msg) => 'NOTICE 001USER :' . $msg->trailing);
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $this->connectionHolder->setProtocolModule($module);

        $this->adapter->sendMessage('001USER', "Line1\n\nLine2", 'NOTICE');

        self::assertSame(['NOTICE 001USER :Line1', 'NOTICE 001USER :Line2'], $lines);
    }
}
