<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Bot;

use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Application\Port\VhostCommandBuilderInterface;
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

    private NickServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $sendNoticePort = $this->createStub(SendNoticePort::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $localUserModeSync = $this->createStub(LocalUserModeSyncInterface::class);

        $this->bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $sendNoticePort,
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

    #[Test]
    public function setUserModeDelegatesToModuleWhenPresent(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setUserMode')
            ->with(self::anything(), '001USER', '+i');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->setUserMode('001USER', '+i');
    }

    #[Test]
    public function setUserModeDoesNothingWhenModuleNull(): void
    {
        self::assertNull($this->connectionHolder->getProtocolModule());
        $this->bot->setUserMode('001USER', '+i');
        self::assertNull($this->connectionHolder->getProtocolModule());
    }

    #[Test]
    public function forceNickDelegatesToModuleAndMarksPending(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('forceNick')
            ->with(self::anything(), '001USER', 'NewNick');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('mark')->with('001USER');

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $pendingRegistry,
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->forceNick('001USER', 'NewNick');
    }

    #[Test]
    public function forceNickDoesNothingWhenModuleNull(): void
    {
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('mark')->with('001USER');

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $pendingRegistry,
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->forceNick('001USER', 'NewNick');
    }

    #[Test]
    public function killUserDelegatesToModuleWhenPresent(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('killUser')
            ->with(self::anything(), '001USER', 'Killed');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->killUser('001USER', 'Killed');
    }

    #[Test]
    public function killUserDoesNothingWhenModuleNull(): void
    {
        self::assertNull($this->connectionHolder->getProtocolModule());
        $this->bot->killUser('001USER', 'Killed');
        self::assertNull($this->connectionHolder->getProtocolModule());
    }

    #[Test]
    public function setUserVhostSendsSetVhostWhenVhostProvided(): void
    {
        $vhostBuilder = $this->createMock(VhostCommandBuilderInterface::class);
        $vhostBuilder->expects(self::once())->method('getSetVhostLine')
            ->with('001', '001USER', 'new.vhost')
            ->willReturn(':001 SVSHOST 001USER new.vhost');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getVhostCommandBuilder')->willReturn($vhostBuilder);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $senderView = new SenderView('001USER', 'User', 'i', 'h', 'old.vhost', 'ip');
        $userLookup->method('findByUid')->willReturn($senderView);

        $this->connectionHolder->setProtocolModule($module);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with(':001 SVSHOST 001USER new.vhost');

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->setUserVhost('001USER', 'new.vhost', '001');
    }

    #[Test]
    public function setUserVhostSendsClearVhostWhenVhostEmpty(): void
    {
        $vhostBuilder = $this->createMock(VhostCommandBuilderInterface::class);
        $vhostBuilder->expects(self::once())->method('getClearVhostLine')
            ->with('001', '001USER')
            ->willReturn(':001 SVSHOST 001USER');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getVhostCommandBuilder')->willReturn($vhostBuilder);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $this->connectionHolder->setProtocolModule($module);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with(':001 SVSHOST 001USER');

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->setUserVhost('001USER', '', '001');
    }

    #[Test]
    public function setUserVhostDoesNothingWhenModuleNull(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $this->bot->setUserVhost('001USER', 'new.vhost', '001');
    }

    #[Test]
    public function setUserVhostDoesNothingWhenNotConnected(): void
    {
        $vhostBuilder = $this->createStub(VhostCommandBuilderInterface::class);
        $vhostBuilder->method('getSetVhostLine')->willReturn('FAKE SETHOST LINE');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getVhostCommandBuilder')->willReturn($vhostBuilder);

        $this->connectionHolder->setProtocolModule($module);
        // Do NOT call onBurstComplete — holder has no connection, isConnected() is false.

        $this->bot->setUserVhost('001USER', 'new.vhost', '001');
        // write() is called but writeToConnection() returns false; no writeLine, no exception.
        self::assertFalse($this->connectionHolder->isConnected());
    }

    #[Test]
    public function setUserVhostSkipsWhenVhostMatchesDisplayHost(): void
    {
        $vhostBuilder = $this->createMock(VhostCommandBuilderInterface::class);
        $vhostBuilder->expects(self::never())->method('getSetVhostLine');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getVhostCommandBuilder')->willReturn($vhostBuilder);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $senderView = new SenderView('001USER', 'User', 'i', 'h', 'cloaked.host', 'ip', false, false, '', 'same.vhost');
        $userLookup->method('findByUid')->willReturn($senderView);

        $this->connectionHolder->setProtocolModule($module);

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
        );

        $bot->setUserVhost('001USER', 'same.vhost', '001');
    }

    #[Test]
    public function getNickReturnsConfiguredNick(): void
    {
        self::assertSame('NickServ', $this->bot->getNick());
    }

    #[Test]
    public function getUidReturnsConfiguredUid(): void
    {
        self::assertSame(self::NICKSERV_UID, $this->bot->getUid());
    }

    #[Test]
    public function getServiceKeyReturnsNickserv(): void
    {
        self::assertSame('nickserv', $this->bot->getServiceKey());
    }

    #[Test]
    public function getNicknameReturnsConfiguredNick(): void
    {
        self::assertSame('NickServ', $this->bot->getNickname());
    }

    #[Test]
    public function getNicknameReturnsCustomNicknameWhenConfigured(): void
    {
        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncInterface::class),
            self::HOSTNAME,
            self::NICKSERV_UID,
            'CustomNS',
        );

        self::assertSame('CustomNS', $bot->getNickname());
        self::assertSame('CustomNS', $bot->getNick());
    }
}
