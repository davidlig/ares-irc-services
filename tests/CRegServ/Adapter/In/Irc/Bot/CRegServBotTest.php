<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Bot;

use App\CRegServ\Adapter\In\Irc\Bot\CRegServBot;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(CRegServBot::class)]
final class CRegServBotTest extends TestCase
{
    private const string CREGSERV_UID = '001DC';

    private const string HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private ServiceUidGeneratorInterface $uidGenerator;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $this->uidGenerator->method('generateUid')->willReturn(self::CREGSERV_UID);
    }

    private function createBot(
        ?SendNoticePort $sendNoticePort = null,
        ?LoggerInterface $logger = null,
    ): CRegServBot {
        return new CRegServBot(
            $this->connectionHolder,
            $sendNoticePort ?? $this->createStub(SendNoticePort::class),
            $this->uidGenerator,
            self::HOSTNAME,
            logger: $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsBurstCompleteWithPriority(): void
    {
        self::assertSame(
            [ServiceIntroductionRequestedEvent::class => ['onBurstComplete', 93]],
            CRegServBot::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onBurstCompleteCallsIntroduceServiceWhenModulePresent(): void
    {
        $bot = $this->createBot();
        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('introduceService')->with(
            '001',
            'CReG',
            'CReG',
            self::HOSTNAME,
            self::CREGSERV_UID,
            'Channel Registration Requests',
            'cregserv',
        );
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));
    }

    #[Test]
    public function onBurstCompleteStoresTheGeneratedUid(): void
    {
        $bot = $this->createBot();
        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        self::assertSame(self::CREGSERV_UID, $bot->getUid());
    }

    #[Test]
    public function onBurstCompleteReturnsEarlyWhenModuleIsNull(): void
    {
        $bot = $this->createBot();

        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        self::assertNull($this->connectionHolder->getProtocolModule());
    }

    #[Test]
    public function sendNoticeDelegatesToSendNoticePort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendNotice')->with(self::CREGSERV_UID, '001USER', 'Hi');

        $bot = $this->createBot($sendNoticePort);
        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $bot->sendNotice('001USER', 'Hi');
    }

    #[Test]
    public function sendMessageDelegatesToSendNoticePort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendMessage')->with(self::CREGSERV_UID, '001USER', 'Hi', 'PRIVMSG');

        $bot = $this->createBot($sendNoticePort);
        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $bot->sendMessage('001USER', 'Hi', 'PRIVMSG');
    }

    #[Test]
    public function getNickReturnsConfiguredNick(): void
    {
        $bot = $this->createBot();

        self::assertSame('CReG', $bot->getNick());
    }

    #[Test]
    public function getUidReturnsEmptyStringBeforeTheBurst(): void
    {
        $bot = $this->createBot();

        self::assertSame('', $bot->getUid());
    }

    #[Test]
    public function getServiceKeyReturnsCregserv(): void
    {
        $bot = $this->createBot();

        self::assertSame('cregserv', $bot->getServiceKey());
    }

    #[Test]
    public function getNicknameReturnsConfiguredNick(): void
    {
        $bot = $this->createBot();

        self::assertSame('CReG', $bot->getNickname());
    }
}
