<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Bot;

use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\OperServ\Bot\OperServBot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

#[CoversClass(OperServBot::class)]
final class OperServBotTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    private NetworkUserLookupPort $userLookup;

    private SendNoticePort $sendNoticePort;

    private LoggerInterface $logger;

    private string $servicesVhost = 'services.example.com';

    private string $operservUid = '123456789ABC';

    private string $operservNick = 'OperServ';

    private string $operservIdent = 'OperServ';

    private string $operservRealname = 'Network Operations Services';

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->userLookup = $this->createStub(NetworkUserLookupPort::class);
        $this->sendNoticePort = $this->createStub(SendNoticePort::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function createBot(): OperServBot
    {
        return new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $this->sendNoticePort,
            $this->servicesVhost,
            $this->operservUid,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $this->logger,
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEventsArray(): void
    {
        $events = OperServBot::getSubscribedEvents();

        self::assertArrayHasKey(NetworkBurstCompleteEvent::class, $events);
        self::assertSame(['onBurstComplete', 90], $events[NetworkBurstCompleteEvent::class]);
    }

    #[Test]
    public function onBurstCompleteIntroducesBot(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $introductionFormatter = $this->createMock(ServiceIntroductionFormatterInterface::class);

        $serverSid = '001';
        $expectedLine = ':001 UID OperServ OperServ services.example.com 123456789ABC * +o :Network Operations Services';

        $this->connectionHolder->setProtocolModule($protocolModule);

        $protocolModule
            ->method('getIntroductionFormatter')
            ->willReturn($introductionFormatter);

        $introductionFormatter
            ->expects(self::once())
            ->method('formatIntroduction')
            ->with(
                $serverSid,
                $this->operservNick,
                $this->operservIdent,
                $this->servicesVhost,
                $this->operservUid,
                $this->operservRealname,
            )
            ->willReturn($expectedLine);

        $connection
            ->expects(self::once())
            ->method('writeLine')
            ->with($expectedLine);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $this->sendNoticePort,
            $this->servicesVhost,
            $this->operservUid,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $logger,
        );

        $event = new NetworkBurstCompleteEvent($connection, $serverSid);

        $bot->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNothingIfProtocolModuleIsNull(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $serverSid = '001';

        $connection->expects(self::never())->method('writeLine');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $this->sendNoticePort,
            $this->servicesVhost,
            $this->operservUid,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $logger,
        );

        $event = new NetworkBurstCompleteEvent($connection, $serverSid);

        $bot->onBurstComplete($event);
    }

    #[Test]
    public function sendNoticeDelegatesToSendNoticePort(): void
    {
        $targetUidOrNick = 'ABC123456';
        $message = 'Test notice message';

        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort
            ->expects(self::once())
            ->method('sendNotice')
            ->with($this->operservUid, $targetUidOrNick, $message);

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $sendNoticePort,
            $this->servicesVhost,
            $this->operservUid,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $this->logger,
        );

        $bot->sendNotice($targetUidOrNick, $message);
    }

    #[Test]
    public function sendMessageDelegatesToSendNoticePortWithMessageType(): void
    {
        $targetUidOrNick = 'ABC123456';
        $message = 'Test message';
        $messageType = 'PRIVMSG';

        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort
            ->expects(self::once())
            ->method('sendMessage')
            ->with($this->operservUid, $targetUidOrNick, $message, $messageType);

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $sendNoticePort,
            $this->servicesVhost,
            $this->operservUid,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $this->logger,
        );

        $bot->sendMessage($targetUidOrNick, $message, $messageType);
    }

    #[Test]
    public function getNickReturnsConfiguredNick(): void
    {
        $bot = $this->createBot();

        self::assertSame($this->operservNick, $bot->getNick());
    }

    #[Test]
    public function getUidReturnsConfiguredUid(): void
    {
        $bot = $this->createBot();

        self::assertSame($this->operservUid, $bot->getUid());
    }

    #[Test]
    public function botImplementsOperServNotifierInterface(): void
    {
        $bot = $this->createBot();

        self::assertInstanceOf(OperServNotifierInterface::class, $bot);
    }

    #[Test]
    public function botImplementsEventSubscriberInterface(): void
    {
        $bot = $this->createBot();

        self::assertInstanceOf(EventSubscriberInterface::class, $bot);
    }

    #[Test]
    public function getServiceKeyReturnsOperserv(): void
    {
        $bot = $this->createBot();

        self::assertSame('operserv', $bot->getServiceKey());
    }

    #[Test]
    public function getNicknameReturnsConfiguredNick(): void
    {
        $bot = $this->createBot();

        self::assertSame($this->operservNick, $bot->getNickname());
    }
}
