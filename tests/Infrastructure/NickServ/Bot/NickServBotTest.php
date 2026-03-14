<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Bot;

use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\LocalUserModeSyncInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\NickServ\Bot\NickServBot;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(NickServBot::class)]
final class NickServBotTest extends TestCase
{
    private const NICKSERV_UID = '001NS';

    private const HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private SendNoticePort&MockObject $sendNoticePort;

    private NickServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $this->sendNoticePort = $this->createMock(SendNoticePort::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $localUserModeSync = $this->createStub(LocalUserModeSyncInterface::class);

        $this->bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->sendNoticePort,
            $pendingRegistry,
            $localUserModeSync,
            self::HOSTNAME,
            self::NICKSERV_UID,
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsBurstCompleteWithPriority(): void
    {
        self::assertSame(
            [NetworkBurstCompleteEvent::class => ['onBurstComplete', 100]],
            NickServBot::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onBurstCompleteWritesIntroductionLineWhenModulePresent(): void
    {
        $introLine = ':001 UID NickServ NickServ 0 0 services.example.com 001NS 0 * Nickname Registration Services';
        $connection = $this->createMock(ConnectionInterface::class);
        $formatter = $this->createMock(ServiceIntroductionFormatterInterface::class);
        $formatter->expects(self::atLeastOnce())->method('formatIntroduction')->with(
            '001',
            'NickServ',
            'NickServ',
            self::HOSTNAME,
            self::NICKSERV_UID,
            'Nickname Registration Services',
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
    public function sendNoticeDelegatesToPort(): void
    {
        $this->sendNoticePort->expects(self::once())->method('sendNotice')->with('001USER', 'Hello');

        $this->bot->sendNotice('001USER', 'Hello');
    }
}
