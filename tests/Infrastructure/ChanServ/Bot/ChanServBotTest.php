<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Bot;

use App\Application\Port\ApplyOutgoingChannelModesPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceChannelRegistrationPort;
use App\Infrastructure\ChanServ\Bot\ChanServBot;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServBot::class)]
final class ChanServBotTest extends TestCase
{
    private const string CHANSERV_UID = '001CS';

    private const string HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private ChannelLookupPort $channelLookup;

    private ApplyOutgoingChannelModesPort $applyOutgoingChannelModes;

    private ServiceChannelRegistrationPort $channelRegistration;

    private SendNoticePort $sendNoticePort;

    private ServiceUidGeneratorInterface $uidGenerator;

    private ChanServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->channelLookup = $this->createStub(ChannelLookupPort::class);
        $this->applyOutgoingChannelModes = $this->createStub(ApplyOutgoingChannelModesPort::class);
        $this->channelRegistration = $this->createStub(ServiceChannelRegistrationPort::class);
        $this->sendNoticePort = $this->createStub(SendNoticePort::class);
        $this->uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $this->uidGenerator->method('generateUid')->willReturn(self::CHANSERV_UID);

        $this->bot = new ChanServBot(
            $this->connectionHolder,
            $this->channelLookup,
            $this->applyOutgoingChannelModes,
            $this->channelRegistration,
            $this->sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );

        $this->bot->onBurstComplete(new NetworkBurstCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));
    }

    #[Test]
    public function getSubscribedEventsReturnsBurstCompleteWithPriority(): void
    {
        self::assertSame(
            [NetworkBurstCompleteEvent::class => ['onBurstComplete', 95]],
            ChanServBot::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onBurstCompleteCallsIntroduceServiceWhenModulePresent(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('introduceService')->with(
            '001',
            'ChanServ',
            'ChanServ',
            self::HOSTNAME,
            self::CHANSERV_UID,
            'Channel Registration Services',
            'chanserv',
        );
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->bot->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNotCallIntroduceServiceWhenModuleNull(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->bot->onBurstComplete($event);

        self::assertNull($this->connectionHolder->getProtocolModule());
    }

    #[Test]
    public function sendNoticeDelegatesToSendNoticePort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendNotice')->with(self::CHANSERV_UID, '001USER', 'Hi');

        $bot = new ChanServBot(
            $this->connectionHolder,
            $this->channelLookup,
            $this->applyOutgoingChannelModes,
            $this->channelRegistration,
            $sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );
        $bot->onBurstComplete(new NetworkBurstCompleteEvent($this->createStub(ConnectionInterface::class), '001'));

        $bot->sendNotice('001USER', 'Hi');
    }

    #[Test]
    public function sendMessageDelegatesToSendNoticePort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendMessage')->with(self::CHANSERV_UID, '001USER', 'Hi', 'PRIVMSG');

        $bot = new ChanServBot(
            $this->connectionHolder,
            $this->channelLookup,
            $this->applyOutgoingChannelModes,
            $this->channelRegistration,
            $sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );
        $bot->onBurstComplete(new NetworkBurstCompleteEvent($this->createStub(ConnectionInterface::class), '001'));

        $bot->sendMessage('001USER', 'Hi', 'PRIVMSG');
    }

    #[Test]
    public function sendNoticeToChannelWhenNoMembersReturnsEarly(): void
    {
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::never())->method('sendNoticeToChannel');

        $bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $this->applyOutgoingChannelModes,
            $this->channelRegistration,
            $sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );

        $bot->sendNoticeToChannel('#channel', 'Hi');
    }

    #[Test]
    public function sendNoticeToChannelWhenMemberCountZeroReturnsEarly(): void
    {
        $channelView = new ChannelView('#test', '', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::never())->method('sendNoticeToChannel');

        $bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $this->applyOutgoingChannelModes,
            $this->channelRegistration,
            $sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );

        $bot->sendNoticeToChannel('#test', 'Hi');
    }

    #[Test]
    public function sendNoticeToChannelWithMembersDelegatesToSendNoticePort(): void
    {
        $channelView = new ChannelView('#test', '', null, 5);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendNoticeToChannel')->with(self::CHANSERV_UID, '#test', 'Hi');

        $bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $this->applyOutgoingChannelModes,
            $this->channelRegistration,
            $sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );
        $bot->onBurstComplete(new NetworkBurstCompleteEvent($this->createStub(ConnectionInterface::class), '001'));

        $bot->sendNoticeToChannel('#test', 'Hi');
    }

    #[Test]
    public function setChannelModesWhenModuleNullReturnsEarly(): void
    {
        $this->bot->setChannelModes('#channel', '+k', ['secretkey']);

        self::assertNull($this->connectionHolder->getProtocolModule());
    }

    #[Test]
    public function setChannelModesSuccessDelegatesToModule(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setChannelModes');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $applyOutgoing = $this->createMock(ApplyOutgoingChannelModesPort::class);
        $applyOutgoing->expects(self::once())->method('applyOutgoingChannelModes');

        $bot = new ChanServBot(
            $this->connectionHolder,
            $this->createStub(ChannelLookupPort::class),
            $applyOutgoing,
            $this->createStub(ServiceChannelRegistrationPort::class),
            $this->sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );

        $bot->onBurstComplete($event);
        $bot->setChannelModes('#channel', '+k', ['secretkey']);
    }

    #[Test]
    public function setChannelMemberModeSuccessDelegatesToModule(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setChannelMemberMode');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->setChannelMemberMode('#channel', '001USER', 'o', true);
    }

    #[Test]
    public function setChannelMemberModeWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('setChannelMemberMode');

        $this->bot->setChannelMemberMode('#channel', '001USER', 'o', true);
    }

    #[Test]
    public function setChannelMemberModeRemoveMode(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setChannelMemberMode')
            ->with('001', '#channel', '001USER', 'o', false, self::CHANSERV_UID);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->setChannelMemberMode('#channel', '001USER', 'o', false);
    }

    #[Test]
    public function inviteToChannelSuccessDelegatesToModule(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('inviteUserToChannel');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->inviteToChannel('#channel', '001USER');
    }

    #[Test]
    public function inviteToChannelWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('inviteUserToChannel');

        $this->bot->inviteToChannel('#channel', '001USER');
    }

    #[Test]
    public function joinChannelAsServiceSuccessWithPrefixIteration(): void
    {
        $channelModeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $channelModeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('joinChannelAsService')->with(
            '001',
            '#channel',
            self::CHANSERV_UID,
            'q',
            self::callback(static fn (int $ts): bool => $ts > 0),
        );

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);
        $module->method('getChannelModeSupport')->willReturn($channelModeSupport);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->joinChannelAsService('#channel');
    }

    #[Test]
    public function joinChannelAsServiceFallsBackToLowerPrefix(): void
    {
        $channelModeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $channelModeSupport->method('getSupportedPrefixModes')->willReturn(['o', 'h', 'v']);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('joinChannelAsService')->with(
            '001',
            '#channel',
            self::CHANSERV_UID,
            'o',
            self::callback(static fn (int $ts): bool => $ts > 0),
        );

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);
        $module->method('getChannelModeSupport')->willReturn($channelModeSupport);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->joinChannelAsService('#channel');
    }

    #[Test]
    public function joinChannelAsServiceWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('joinChannelAsService');

        $this->bot->joinChannelAsService('#channel');
    }

    #[Test]
    public function setChannelTopicSuccessDelegatesToModule(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setChannelTopic');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->setChannelTopic('#channel', 'New topic');
    }

    #[Test]
    public function setChannelTopicNullTopic(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setChannelTopic')
            ->with('001', '#channel', null, self::CHANSERV_UID, null);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->setChannelTopic('#channel', null);
    }

    #[Test]
    public function setChannelTopicWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('setChannelTopic');

        $this->bot->setChannelTopic('#channel', 'Topic');
    }

    #[Test]
    public function kickFromChannelWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('kickFromChannel');

        $this->bot->kickFromChannel('#channel', '001USER', 'Test reason');
    }

    #[Test]
    public function kickFromChannelWithModuleDelegates(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('kickFromChannel')
            ->with('001', '#channel', '001USER', 'Test reason', self::CHANSERV_UID);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->kickFromChannel('#channel', '001USER', 'Test reason');
    }

    #[Test]
    public function partChannelAsServiceWithModuleDelegates(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('partChannelAsService')
            ->with('001', '#channel', self::CHANSERV_UID);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->partChannelAsService('#channel');
    }

    #[Test]
    public function partChannelAsServiceWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('partChannelAsService');

        $this->bot->partChannelAsService('#channel');
    }

    #[Test]
    public function getNickReturnsCorrectValue(): void
    {
        self::assertSame('ChanServ', $this->bot->getNick());
    }

    #[Test]
    public function getUidReturnsCorrectValue(): void
    {
        self::assertSame(self::CHANSERV_UID, $this->bot->getUid());
    }

    #[Test]
    public function getServiceKeyReturnsChanserv(): void
    {
        self::assertSame('chanserv', $this->bot->getServiceKey());
    }

    #[Test]
    public function getNicknameReturnsConfiguredNick(): void
    {
        self::assertSame('ChanServ', $this->bot->getNickname());
    }
}
