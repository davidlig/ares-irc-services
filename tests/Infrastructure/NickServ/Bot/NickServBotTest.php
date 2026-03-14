<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Bot;

use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\LocalUserModeSyncInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\NickServ\Bot\NickServBot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServBot::class)]
final class NickServBotTest extends TestCase
{
    private const NICKSERV_UID = '001NS';

    private const HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private SendNoticePort $sendNoticePort;

    private NickServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $this->sendNoticePort = $this->createStub(SendNoticePort::class);
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
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::never())->method('sendNotice');
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
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getIntroductionFormatter')->willReturn($formatter);

        $this->connectionHolder->setProtocolModule($module);
        $connection->expects(self::once())->method('writeLine')->with($introLine);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $bot->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNotWriteWhenModuleNull(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::never())->method('sendNotice');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $bot->onBurstComplete($event);
    }

    #[Test]
    public function sendNoticeDelegatesToPort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendNotice')->with('001USER', 'Hello');

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );
        $bot->sendNotice('001USER', 'Hello');
    }

    #[Test]
    public function sendMessageDelegatesToPort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendMessage')
            ->with('001USER', 'Message', 'NOTICE');

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );
        $bot->sendMessage('001USER', 'Message', 'NOTICE');
    }

    #[Test]
    public function setUserAccountDelegatesToModuleWhenPresent(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setUserAccount')
            ->with(self::anything(), '001USER', 'AccountName');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $localUserModeSync = $this->createMock(LocalUserModeSyncInterface::class);
        $localUserModeSync->expects(self::once())->method('apply')
            ->with(self::callback(static fn ($u): bool => '001USER' === $u->value), '+r');

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $localUserModeSync,
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->setUserAccount('001USER', 'AccountName');
    }

    #[Test]
    public function setUserAccountDoesNothingWhenModuleNull(): void
    {
        self::assertNull($this->connectionHolder->getProtocolModule());
        $this->bot->setUserAccount('001USER', 'AccountName');
        self::assertNull($this->connectionHolder->getProtocolModule());
    }
}
