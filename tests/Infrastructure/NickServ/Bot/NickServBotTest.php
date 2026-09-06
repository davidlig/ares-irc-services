<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Bot;

use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceUidGeneratorInterface;
use App\Infrastructure\NickServ\Bot\NickServBot;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\Port\In\LocalUserModeSyncPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Domain\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServBot::class)]
final class NickServBotTest extends TestCase
{
    private const string NICKSERV_UID = '001NS';

    private const string HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private ServiceUidGeneratorInterface $uidGenerator;

    private NickServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $sendNoticePort = $this->createStub(SendNoticePort::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $localUserModeSync = $this->createStub(LocalUserModeSyncPort::class);
        $this->uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $this->uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $this->bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $sendNoticePort,
            $pendingRegistry,
            $localUserModeSync,
            $this->uidGenerator,
            self::HOSTNAME,
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
    public function onBurstCompleteCallsIntroduceServiceWhenModulePresent(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::never())->method('sendNotice');
        $connection = $this->createStub(ConnectionInterface::class);
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('introduceService')->with(
            '001',
            'NickServ',
            'NickServ',
            self::HOSTNAME,
            self::NICKSERV_UID,
            'Nickname Registration Services',
            'nickserv',
        );
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
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

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
        );
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $bot->onBurstComplete($event);
    }

    #[Test]
    public function sendNoticeDelegatesToPort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendNotice')->with(self::NICKSERV_UID, '001USER', 'Hello');

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
        );
        $bot->onBurstComplete(new NetworkBurstCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
        $bot->sendNotice('001USER', 'Hello');
    }

    #[Test]
    public function sendMessageDelegatesToPort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendMessage')
            ->with(self::NICKSERV_UID, '001USER', 'Message', 'NOTICE');

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $sendNoticePort,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
        );
        $bot->onBurstComplete(new NetworkBurstCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
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

        $localUserModeSync = $this->createMock(LocalUserModeSyncPort::class);
        $localUserModeSync->expects(self::once())->method('apply')
            ->with(self::callback(static fn (Uid $uid): bool => '001USER' === $uid->value), '+r');

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $localUserModeSync,
            $uidGenerator,
            self::HOSTNAME,
        );

        $bot->setUserAccount('001USER', 'AccountName');
    }

    #[Test]
    public function setUserAccountLogoutAppliesMinusRMode(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setUserAccount')
            ->with(self::anything(), '001USER', '0');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $localUserModeSync = $this->createMock(LocalUserModeSyncPort::class);
        $localUserModeSync->expects(self::once())->method('apply')
            ->with(self::callback(static fn (Uid $uid): bool => '001USER' === $uid->value), '-r');

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $localUserModeSync,
            $uidGenerator,
            self::HOSTNAME,
        );

        $bot->setUserAccount('001USER', '0');
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

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
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

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $pendingRegistry,
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
        );

        $bot->forceNick('001USER', 'NewNick');
    }

    #[Test]
    public function forceNickDoesNothingWhenModuleNull(): void
    {
        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('mark')->with('001USER');

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $pendingRegistry,
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
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

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
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
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setUserVhost')
            ->with('001', '001USER', 'new.vhost', 'old.vhost');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $senderView = new SenderView('001USER', 'User', 'i', 'h', 'old.vhost', 'ip');
        $userLookup->method('findByUid')->willReturn($senderView);

        $this->connectionHolder->setProtocolModule($module);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
        );

        $bot->setUserVhost('001USER', 'new.vhost', '001');
    }

    #[Test]
    public function setUserVhostSendsClearVhostWhenVhostEmpty(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setUserVhost')
            ->with('001', '001USER', '', 'cloak.host');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'real.host',
            cloakedHost: 'cloak.host',
            ipBase64: '',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
            displayHost: 'old.vhost',
        );

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);
        $userLookup->expects(self::once())->method('updateVhost')->with('001USER', '*');

        $this->connectionHolder->setProtocolModule($module);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
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
    public function setUserVhostSkipsWhenVhostMatchesDisplayHost(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('setUserVhost');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $senderView = new SenderView('001USER', 'User', 'i', 'h', 'cloaked.host', 'ip', false, false, '', 'same.vhost');
        $userLookup->method('findByUid')->willReturn($senderView);

        $this->connectionHolder->setProtocolModule($module);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
        );

        $bot->setUserVhost('001USER', 'same.vhost', '001');
    }

    #[Test]
    public function setUserVhostClearUsesCloakedHostNotHostname(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setUserVhost')
            ->with('001', '001USER', '', 'safe.cloaked.host');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: 'test',
            hostname: '5.224.47.252',
            cloakedHost: 'safe.cloaked.host',
            ipBase64: '',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
            displayHost: 'old.vhost',
        );

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);
        $userLookup->expects(self::once())->method('updateVhost')->with('001USER', '*');

        $this->connectionHolder->setProtocolModule($module);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $userLookup,
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
        );

        $bot->setUserVhost('001USER', '', '001');
    }

    #[Test]
    public function getNickReturnsConfiguredNick(): void
    {
        self::assertSame('NickServ', $this->bot->getNick());
    }

    #[Test]
    public function getUidReturnsConfiguredUid(): void
    {
        $this->bot->onBurstComplete(new NetworkBurstCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
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
        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::NICKSERV_UID);

        $bot = new NickServBot(
            $this->connectionHolder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(LocalUserModeSyncPort::class),
            $uidGenerator,
            self::HOSTNAME,
            'CustomNS',
        );

        self::assertSame('CustomNS', $bot->getNickname());
        self::assertSame('CustomNS', $bot->getNick());
    }
}
