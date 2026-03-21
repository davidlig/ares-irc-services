<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Bot;

use App\Application\Port\ApplyOutgoingChannelModesPort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\ChanServ\Bot\ChanServBot;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ChanServBot::class)]
final class ChanServBotTest extends TestCase
{
    private const CHANSERV_UID = '001CS';

    private const HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private ChanServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $applyOutgoingChannelModes = $this->createStub(ApplyOutgoingChannelModesPort::class);

        $this->bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $applyOutgoingChannelModes,
            self::HOSTNAME,
            self::CHANSERV_UID,
        );
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
    public function onBurstCompleteWritesIntroductionLineWhenModulePresent(): void
    {
        $introLine = ':001 UID ChanServ ChanServ 0 0 services.example.com 001CS 0 * Channel Registration Services';
        $connection = $this->createMock(ConnectionInterface::class);
        $formatter = $this->createMock(ServiceIntroductionFormatterInterface::class);
        $formatter->expects(self::atLeastOnce())->method('formatIntroduction')->with(
            '001',
            'ChanServ',
            'ChanServ',
            self::HOSTNAME,
            self::CHANSERV_UID,
            'Channel Registration Services',
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

    private function createModuleWithHandlerThatReturnsLine(string $line): ProtocolModuleInterface
    {
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturn($line);
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);

        return $module;
    }

    #[Test]
    public function sendMessageWhenNotConnectedReturnsEarly(): void
    {
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE 001USER :Hi'));
        $this->bot->sendMessage('001USER', 'Hi', 'NOTICE');
        self::assertTrue(true);
    }

    #[Test]
    public function sendMessageWhenModuleNullReturnsEarly(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);

        $this->bot->sendMessage('001USER', 'Hi', 'NOTICE');
    }

    #[Test]
    public function sendNoticeToChannelWhenNotConnectedReturnsEarly(): void
    {
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE #channel :Hi'));
        $this->bot->sendNoticeToChannel('#channel', 'Hi');
        self::assertTrue(true);
    }

    #[Test]
    public function sendNoticeToChannelWhenNoMembersReturnsEarly(): void
    {
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $this->createStub(ApplyOutgoingChannelModesPort::class),
            self::HOSTNAME,
            self::CHANSERV_UID,
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE #channel :Hi'));

        $bot->sendNoticeToChannel('#channel', 'Hi');
    }

    #[Test]
    public function setChannelModesWhenModuleNullReturnsEarly(): void
    {
        $this->bot->setChannelModes('#channel', '+k', ['secretkey']);
        self::assertTrue(true);
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
            null,
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
    public function sendMessageMultiLineSendsEachLine(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(2))->method('writeLine');
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE 001USER :Line'));

        $this->bot->sendMessage('001USER', "Line1\nLine2", 'NOTICE');
    }

    #[Test]
    public function sendMessagePRIVMSGUsesPRIVMSGCommand(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->with(self::stringContains('PRIVMSG'));
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('PRIVMSG 001USER :Hi'));

        $this->bot->sendMessage('001USER', 'Hi', 'PRIVMSG');
    }

    #[Test]
    public function sendNoticeToChannelWithMembersWritesLine(): void
    {
        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 5);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $this->createStub(ApplyOutgoingChannelModesPort::class),
            self::HOSTNAME,
            self::CHANSERV_UID,
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine');
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE #test :Hi'));

        $bot->sendNoticeToChannel('#test', 'Hi');
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
            self::HOSTNAME,
            self::CHANSERV_UID,
        );

        $bot->setChannelModes('#channel', '+k', ['secretkey']);
    }

    #[Test]
    public function setChannelMemberModeWithModuleDelegatesToServiceActions(): void
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
    public function inviteToChannelWithModuleDelegates(): void
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
    public function joinChannelAsServiceWithModuleDelegates(): void
    {
        $channelModeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $channelModeSupport->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('joinChannelAsService');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);
        $module->method('getChannelModeSupport')->willReturn($channelModeSupport);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->joinChannelAsService('#channel', 12345);
    }

    #[Test]
    public function setChannelTopicWithModuleDelegates(): void
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
    public function inviteToChannelWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('inviteUserToChannel');

        $this->bot->inviteToChannel('#channel', '001USER');
    }

    #[Test]
    public function joinChannelAsServiceWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('joinChannelAsService');

        $this->bot->joinChannelAsService('#channel');
    }

    #[Test]
    public function setChannelTopicWhenModuleNullReturnsEarly(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('setChannelTopic');

        $this->bot->setChannelTopic('#channel', 'Topic');
    }

    #[Test]
    public function sendNoticeToChannelWhenMemberCountZeroReturnsEarly(): void
    {
        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $this->createStub(ApplyOutgoingChannelModesPort::class),
            self::HOSTNAME,
            self::CHANSERV_UID,
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE #test :Hi'));

        $bot->sendNoticeToChannel('#test', 'Hi');
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
            null,
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
    public function sendMessageSkipsEmptyLines(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::exactly(2))->method('writeLine');
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE 001USER :Line'));

        $this->bot->sendMessage('001USER', "Line1\n\nLine2", 'NOTICE');
    }

    #[Test]
    public function setChannelTopicNullTopic(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('setChannelTopic')
            ->with('001', '#channel', null, self::CHANSERV_UID);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connection = $this->createStub(ConnectionInterface::class);
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($module);

        $this->bot->setChannelTopic('#channel', null);
    }

    #[Test]
    public function sendNoticeToChannelWhenModuleNullReturnsEarly(): void
    {
        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 5);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $bot = new ChanServBot(
            $this->connectionHolder,
            $channelLookup,
            $this->createStub(ApplyOutgoingChannelModesPort::class),
            self::HOSTNAME,
            self::CHANSERV_UID,
        );

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');
        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        // Do NOT call setProtocolModule, leaving it null

        $bot->sendNoticeToChannel('#test', 'Hi');
    }

    #[Test]
    public function writeToConnectionReturnsFalseWhenDisconnected(): void
    {
        $disconnectedHolder = new ActiveConnectionHolder();
        $disconnectedHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('test'));

        $bot = new ChanServBot(
            $disconnectedHolder,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ApplyOutgoingChannelModesPort::class),
            self::HOSTNAME,
            self::CHANSERV_UID,
        );

        $reflection = new ReflectionClass($bot);
        $method = $reflection->getMethod('writeToConnection');
        $method->setAccessible(true);

        $result = $method->invoke($bot, 'test line');

        self::assertFalse($result);
    }

    #[Test]
    public function writeToConnectionReturnsTrueWhenConnected(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')->with('NOTICE 001USER :Test message');

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $this->connectionHolder->onBurstComplete($event);
        $this->connectionHolder->setProtocolModule($this->createModuleWithHandlerThatReturnsLine('NOTICE 001USER :Test message'));

        $this->bot->sendNotice('001USER', 'Test message');
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
