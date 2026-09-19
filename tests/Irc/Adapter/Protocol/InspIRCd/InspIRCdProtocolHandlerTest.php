<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\InspIRCd;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelModeSupport;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelModeSupportFactory;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdModule;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNickReservation;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolServiceActions;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdServiceIntroductionFormatter;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdUserModeSupport;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(InspIRCdProtocolHandler::class)]
final class InspIRCdProtocolHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $effects = [];

    private function createHandler(
        string $sid = 'A0A',
        ?ActiveConnectionHolder $connectionHolder = null,
        ?InspIRCdChannelModeSupportFactory $factory = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): InspIRCdProtocolHandler {
        return new InspIRCdProtocolHandler(
            sid: $sid,
            connectionHolder: $connectionHolder ?? new ActiveConnectionHolder(),
            modeSupportFactory: $factory ?? new InspIRCdChannelModeSupportFactory(),
            logger: new NullLogger(),
            eventDispatcher: $eventDispatcher,
        );
    }

    private function createServerLink(): ServerLink
    {
        return new ServerLink(
            serverName: new ServerName('services.test.local'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('link-secret'),
            description: 'Ares IRC Services',
            useTls: false,
        );
    }

    /** @param list<string> $written */
    private function createRecordingConnection(array &$written): ConnectionInterface
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        return $connection;
    }

    #[Test]
    public function getProtocolNameReturnsInspircd(): void
    {
        $handler = $this->createHandler();

        self::assertSame('inspircd', $handler->getProtocolName());
    }

    #[Test]
    public function getSupportedCapabilitiesReturnsEmpty(): void
    {
        $handler = $this->createHandler();

        self::assertSame([], $handler->getSupportedCapabilities());
    }

    #[Test]
    public function parseRawLineDelegatesToIRCMessage(): void
    {
        $handler = $this->createHandler();

        $msg = $handler->parseRawLine(':server PRIVMSG #chan :hello');

        self::assertSame('PRIVMSG', $msg->command);
        self::assertSame('server', $msg->prefix);
        self::assertSame(['#chan'], $msg->params);
        self::assertSame('hello', $msg->trailing);
    }

    #[Test]
    public function parseRawLineStripsIrcv3Tags(): void
    {
        $handler = $this->createHandler();

        $msg = $handler->parseRawLine('@time=2026-04-18T21:23:31.529Z;msgid=994~1 :994AAAAAA PRIVMSG 0A0AAAAAA :help');

        self::assertSame('PRIVMSG', $msg->command);
        self::assertSame('994AAAAAA', $msg->prefix);
        self::assertSame(['0A0AAAAAA'], $msg->params);
        self::assertSame('help', $msg->trailing);
    }

    #[Test]
    public function formatMessageDelegatesToIRCMessage(): void
    {
        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'PONG', params: ['target']);

        $raw = $handler->formatMessage($msg);

        self::assertSame('PONG target', $raw);
    }

    #[Test]
    public function performHandshakeSendsMinimalCapabAndServerLine(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler('B1B');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        self::assertSame('CAPAB START 1206', $written[0]);
        self::assertSame('CAPAB CAPABILITIES :CASEMAPPING=ascii', $written[1]);
        self::assertSame('CAPAB END', $written[2]);
        self::assertSame('SERVER services.test.local link-secret B1B :Ares IRC Services', $written[3]);
        self::assertCount(4, $written);
    }

    #[Test]
    public function performHandshakeDoesNotSendModulesOrModes(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler();
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        foreach ($written as $line) {
            self::assertStringNotContainsString('CAPAB MODULES', $line);
            self::assertStringNotContainsString('CAPAB MODSUPPORT', $line);
            self::assertStringNotContainsString('CAPAB CHANMODES', $line);
            self::assertStringNotContainsString('CAPAB USERMODES', $line);
            self::assertStringNotContainsString('CAPAB EXTBANS', $line);
        }
    }

    #[Test]
    public function performHandshakeServerLineHasNoHopCountForV4(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler('0A0');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        $serverLine = $written[3];
        self::assertSame('SERVER services.test.local link-secret 0A0 :Ares IRC Services', $serverLine);
        self::assertStringNotContainsString(' 0 0A0 ', $serverLine);
    }

    #[Test]
    public function capabCapabilitiesContainsCaseMappingWithoutChallenge(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler();
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        $capsLine = $written[1];
        self::assertStringContainsString('CASEMAPPING=ascii', $capsLine);
        self::assertStringNotContainsString('CHALLENGE', $capsLine);
    }

    #[Test]
    public function handleIncomingPingRespondsWithPongWithSidPrefix(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('0A0');
        $msg = new IRCMessage(command: 'PING', prefix: '994', params: ['0A0']);

        $handler->handleIncoming($msg, $connection);

        self::assertSame([':0A0 PONG 994'], $written);
    }

    #[Test]
    public function handleIncomingPingWithoutPrefixUsesParam(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('0A0');
        $msg = new IRCMessage(command: 'PING', params: ['994']);

        $handler->handleIncoming($msg, $connection);

        self::assertSame([':0A0 PONG 994'], $written);
    }

    #[Test]
    public function handleIncomingErrorLogsCritical(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'ERROR', trailing: 'Ping timeout');

        $handler->handleIncoming($msg, $connection);
    }

    #[Test]
    public function directServerStartsBurstAndMatchingEndburstCompletesSyncOncePerHandshake(): void
    {
        $this->effects = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->effects[] = 'write:' . $line;
        });

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')
            ->willReturnCallback(function (NetworkBurstCompleteEvent|NetworkSyncCompleteEvent $event) use ($connection): object {
                self::assertSame($connection, $event->connection);
                self::assertSame('C2C', $event->serverSid);
                $this->effects[] = 'event:' . $event::class;

                return $event;
            });

        $connectionHolder = new ActiveConnectionHolder();
        $handler = $this->createHandler('C2C', $connectionHolder, eventDispatcher: $eventDispatcher);
        $handler->performHandshake($connection, $this->createServerLink());
        $this->effects = [];

        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['hub.example.net', 'pass', '0A1']), $connection);
        $this->assertOutgoingBurstEffects();

        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['hub.example.net', 'pass', '0A1']), $connection);
        $this->assertOutgoingBurstEffects();

        $handler->handleIncoming(new IRCMessage(command: 'SERVER', prefix: '0A1', params: ['leaf.example.net', 'pass', '0A4']), $connection);

        foreach (['0A4', '0A5', '0A2', '0A3'] as $downstreamSid) {
            $handler->handleIncoming(new IRCMessage(command: 'ENDBURST', prefix: $downstreamSid), $connection);
        }

        $handler->handleIncoming(new IRCMessage(command: 'ENDBURST', prefix: '0A1'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'ENDBURST', prefix: '0A1'), $connection);

        self::assertSame('0A1', $connectionHolder->getRemoteServerSid());
        $this->assertCompletedBurstEffects();

        $handler->performHandshake($connection, $this->createServerLink());
        $this->effects = [];

        $handler->handleIncoming(new IRCMessage(command: 'ENDBURST', prefix: '0A1'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', prefix: '0A1', params: ['leaf.example.net', 'pass', '0B4']), $connection);
        self::assertSame([], $this->effects);

        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['new-hub.example.net', 'pass', '0B1']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'ENDBURST', prefix: '0B1'), $connection);

        self::assertSame('0B1', $connectionHolder->getRemoteServerSid());
        $this->assertCompletedBurstEffects();
    }

    private function assertOutgoingBurstEffects(): void
    {
        self::assertCount(3, $this->effects);
        self::assertMatchesRegularExpression('/^write::C2C BURST \d+$/', $this->effects[0]);
        self::assertSame('event:' . NetworkBurstCompleteEvent::class, $this->effects[1]);
        self::assertSame('write::C2C ENDBURST', $this->effects[2]);
    }

    private function assertCompletedBurstEffects(): void
    {
        self::assertCount(4, $this->effects);
        self::assertMatchesRegularExpression('/^write::C2C BURST \d+$/', $this->effects[0]);
        self::assertSame('event:' . NetworkBurstCompleteEvent::class, $this->effects[1]);
        self::assertSame('write::C2C ENDBURST', $this->effects[2]);
        self::assertSame('event:' . NetworkSyncCompleteEvent::class, $this->effects[3]);
    }

    #[Test]
    public function handleIncomingUnknownCommandWritesNothing(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'PRIVMSG', params: ['#chan'], trailing: 'hi');

        $handler->handleIncoming($msg, $connection);
    }

    #[Test]
    public function handleCapabAccumulatesLinesAndUpdatesModuleOnCapabEnd(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $factory = new InspIRCdChannelModeSupportFactory();
        $modeSupport = $factory->createDefault();

        $handler = new InspIRCdProtocolHandler(
            sid: '0A0',
            connectionHolder: $connectionHolder,
            modeSupportFactory: $factory,
            logger: new NullLogger(),
        );

        $module = $this->createModuleWithHandler($handler, $connectionHolder, $modeSupport);
        $connectionHolder->setProtocolModule($module);

        $connection = $this->createStub(ConnectionInterface::class);

        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['START'], trailing: '1206'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['CHANMODES'], trailing: 'list:ban=b list:banexception=e list:invex=I param-set:key=k prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i simple:c_registered=r simple:topiclock=t'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['END']), $connection);

        self::assertFalse($module->getChannelModeSupport()->hasPermanentChannelMode());
        self::assertFalse($module->getChannelModeSupport()->hasHalfOp());
        self::assertFalse($module->getChannelModeSupport()->hasAdmin());
        self::assertFalse($module->getChannelModeSupport()->hasOwner());
        self::assertSame(['v', 'o'], $module->getChannelModeSupport()->getSupportedPrefixModes());
        self::assertTrue($module->getChannelModeSupport()->hasChannelRegisteredMode());
    }

    #[Test]
    public function handleCapabWithFullRanksUpdatesModuleCorrectly(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $factory = new InspIRCdChannelModeSupportFactory();
        $modeSupport = $factory->createDefault();

        $handler = new InspIRCdProtocolHandler(
            sid: '0A0',
            connectionHolder: $connectionHolder,
            modeSupportFactory: $factory,
            logger: new NullLogger(),
        );

        $module = $this->createModuleWithHandler($handler, $connectionHolder, $modeSupport);
        $connectionHolder->setProtocolModule($module);

        $connection = $this->createStub(ConnectionInterface::class);

        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['START'], trailing: '1206'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['CHANMODES'], trailing: 'list:ban=b param-set:key=k prefix:10000:voice=+v prefix:20000:halfop=%h prefix:30000:op=@o prefix:40000:admin=&a prefix:50000:founder=~q simple:c_registered=r simple:P=P simple:inviteonly=i'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['END']), $connection);

        self::assertTrue($module->getChannelModeSupport()->hasHalfOp());
        self::assertTrue($module->getChannelModeSupport()->hasAdmin());
        self::assertTrue($module->getChannelModeSupport()->hasOwner());
        self::assertTrue($module->getChannelModeSupport()->hasPermanentChannelMode());
        self::assertSame('P', $module->getChannelModeSupport()->getPermanentChannelModeLetter());
        self::assertSame(['v', 'h', 'o', 'a', 'q'], $module->getChannelModeSupport()->getSupportedPrefixModes());
    }

    #[Test]
    public function handleCapabDoesNotUpdateModuleWhenNoFactory(): void
    {
        $connectionHolder = new ActiveConnectionHolder();

        $handler = new InspIRCdProtocolHandler(
            sid: '0A0',
            connectionHolder: $connectionHolder,
            modeSupportFactory: null,
            logger: new NullLogger(),
        );

        $factory = new InspIRCdChannelModeSupportFactory();
        $modeSupport = $factory->createDefault();

        $module = $this->createModuleWithHandler($handler, $connectionHolder, $modeSupport);
        $connectionHolder->setProtocolModule($module);

        $connection = $this->createStub(ConnectionInterface::class);

        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['START'], trailing: '1206'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['CHANMODES'], trailing: 'list:ban=b prefix:10000:voice=+v prefix:30000:op=@o simple:inviteonly=i'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['END']), $connection);

        self::assertTrue($module->getChannelModeSupport()->hasPermanentChannelMode());
        self::assertTrue($module->getChannelModeSupport()->hasOwner());
    }

    #[Test]
    public function performHandshakeResetsCapabAccumulation(): void
    {
        $connectionHolder = new ActiveConnectionHolder();
        $factory = new InspIRCdChannelModeSupportFactory();
        $modeSupport = $factory->createDefault();

        $handler = new InspIRCdProtocolHandler(
            sid: '0A0',
            connectionHolder: $connectionHolder,
            modeSupportFactory: $factory,
            logger: new NullLogger(),
        );

        $module = $this->createModuleWithHandler($handler, $connectionHolder, $modeSupport);
        $connectionHolder->setProtocolModule($module);

        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['START'], trailing: '1206'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['CHANMODES'], trailing: 'list:ban=b prefix:10000:voice=+v simple:c_registered=r'), $connection);

        $handler->performHandshake($connection, $this->createServerLink());

        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['START'], trailing: '1206'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['CHANMODES'], trailing: 'list:ban=b param-set:key=k prefix:10000:voice=+v prefix:20000:halfop=%h prefix:30000:op=@o prefix:40000:admin=&a prefix:50000:founder=~q simple:c_registered=r simple:P=P'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'CAPAB', params: ['END']), $connection);

        self::assertTrue($module->getChannelModeSupport()->hasHalfOp());
        self::assertTrue($module->getChannelModeSupport()->hasOwner());
        self::assertTrue($module->getChannelModeSupport()->hasPermanentChannelMode());
    }

    private function createModuleWithHandler(InspIRCdProtocolHandler $handler, ActiveConnectionHolder $connectionHolder, InspIRCdChannelModeSupport $modeSupport): InspIRCdModule
    {
        return new InspIRCdModule(
            handler: $handler,
            serviceActions: new InspIRCdProtocolServiceActions($connectionHolder, new InspIRCdServiceIntroductionFormatter(), new NullLogger()),
            channelModeSupport: $modeSupport,
            userModeSupport: new InspIRCdUserModeSupport(),
            nickReservation: new InspIRCdNickReservation($connectionHolder, new NullLogger()),
        );
    }
}
