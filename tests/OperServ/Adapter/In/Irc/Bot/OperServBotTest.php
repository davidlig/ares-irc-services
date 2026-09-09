<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Bot;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\OperServ\Adapter\In\Irc\Bot\OperServBot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(OperServBot::class)]
final class OperServBotTest extends TestCase
{
    private ActiveConnectionHolder $connectionHolder;

    private NetworkUserLookupPort $userLookup;

    private SendNoticePort $sendNoticePort;

    private ServiceUidGeneratorInterface $uidGenerator;

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
        $this->uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $this->uidGenerator->method('generateUid')->willReturn($this->operservUid);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function createBot(): OperServBot
    {
        return new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $this->sendNoticePort,
            $this->uidGenerator,
            $this->servicesVhost,
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

        self::assertArrayHasKey(ServiceIntroductionRequestedEvent::class, $events);
        self::assertSame(['onBurstComplete', 90], $events[ServiceIntroductionRequestedEvent::class]);
    }

    #[Test]
    public function onBurstCompleteIntroducesBot(): void
    {
        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);

        $serverSid = '001';

        $this->connectionHolder->setProtocolModule($protocolModule);

        $protocolModule
            ->method('getServiceActions')
            ->willReturn($serviceActions);

        $serviceActions
            ->expects(self::once())
            ->method('introduceService')
            ->with(
                $serverSid,
                $this->operservNick,
                $this->operservIdent,
                $this->servicesVhost,
                $this->operservUid,
                $this->operservRealname,
                'operserv',
            );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn($this->operservUid);

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $this->sendNoticePort,
            $uidGenerator,
            $this->servicesVhost,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $logger,
        );

        $event = new ServiceIntroductionRequestedEvent($serverSid);

        $bot->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNothingIfProtocolModuleIsNull(): void
    {
        $serverSid = '001';

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn($this->operservUid);

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $this->sendNoticePort,
            $uidGenerator,
            $this->servicesVhost,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $logger,
        );

        $event = new ServiceIntroductionRequestedEvent($serverSid);

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

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn($this->operservUid);

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $sendNoticePort,
            $uidGenerator,
            $this->servicesVhost,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $this->logger,
        );

        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));
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

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn($this->operservUid);

        $bot = new OperServBot(
            $this->connectionHolder,
            $this->userLookup,
            $sendNoticePort,
            $uidGenerator,
            $this->servicesVhost,
            $this->operservNick,
            $this->operservIdent,
            $this->operservRealname,
            $this->logger,
        );

        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));
        $bot->sendMessage($targetUidOrNick, $message, $messageType);
    }

    #[Test]
    public function getNickReturnsConfiguredNick(): void
    {
        $bot = $this->createBot();

        self::assertSame($this->operservNick, $bot->getNick());
    }

    #[Test]
    public function getUserLookupReturnsConfiguredLookup(): void
    {
        $bot = $this->createBot();

        self::assertSame($this->userLookup, $bot->getUserLookup());
    }

    #[Test]
    public function getUidReturnsConfiguredUid(): void
    {
        $bot = $this->createBot();

        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));
        self::assertSame($this->operservUid, $bot->getUid());
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
