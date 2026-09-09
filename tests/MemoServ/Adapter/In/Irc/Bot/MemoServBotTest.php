<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Bot;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\MemoServ\Adapter\In\Irc\Bot\MemoServBot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(MemoServBot::class)]
final class MemoServBotTest extends TestCase
{
    private const string MEMOSERV_UID = '001MS';

    private const string HOSTNAME = 'services.example.com';

    private ActiveConnectionHolder $connectionHolder;

    private SendNoticePort $sendNoticePort;

    private ServiceUidGeneratorInterface $uidGenerator;

    private MemoServBot $bot;

    protected function setUp(): void
    {
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->sendNoticePort = $this->createStub(SendNoticePort::class);
        $this->uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $this->uidGenerator->method('generateUid')->willReturn(self::MEMOSERV_UID);

        $this->bot = new MemoServBot(
            $this->connectionHolder,
            $this->sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );

        $this->bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));
    }

    #[Test]
    public function getSubscribedEventsReturnsBurstCompleteWithPriority(): void
    {
        self::assertSame(
            [
                ServiceIntroductionRequestedEvent::class => ['onBurstComplete', 94],
            ],
            MemoServBot::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onBurstCompleteCallsIntroduceServiceWhenModulePresent(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('introduceService')->with(
            '001',
            'MemoServ',
            'MemoServ',
            self::HOSTNAME,
            self::MEMOSERV_UID,
            'Memo Service',
            'memoserv',
        );
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $this->connectionHolder->setProtocolModule($module);

        $event = new ServiceIntroductionRequestedEvent('001');
        $this->bot->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNotCallIntroduceServiceWhenModuleNull(): void
    {
        $event = new ServiceIntroductionRequestedEvent('001');
        $this->bot->onBurstComplete($event);

        self::assertNull($this->connectionHolder->getProtocolModule());
    }

    #[Test]
    public function sendNoticeDelegatesToSendNoticePort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendNotice')->with(self::MEMOSERV_UID, '001USER', 'Hi');

        $bot = new MemoServBot(
            $this->connectionHolder,
            $sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );
        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $bot->sendNotice('001USER', 'Hi');
    }

    #[Test]
    public function sendMessageDelegatesToSendNoticePort(): void
    {
        $sendNoticePort = $this->createMock(SendNoticePort::class);
        $sendNoticePort->expects(self::once())->method('sendMessage')->with(self::MEMOSERV_UID, '001USER', 'Hi', 'PRIVMSG');

        $bot = new MemoServBot(
            $this->connectionHolder,
            $sendNoticePort,
            $this->uidGenerator,
            self::HOSTNAME,
        );
        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $bot->sendMessage('001USER', 'Hi', 'PRIVMSG');
    }

    #[Test]
    public function getNickAndGetUidReturnConfiguredValues(): void
    {
        self::assertSame('MemoServ', $this->bot->getNick());
        self::assertSame(self::MEMOSERV_UID, $this->bot->getUid());
    }

    #[Test]
    public function onBurstCompleteLogsIntroduction(): void
    {
        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('introduceService');
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with('MemoServ introduced to network.', [
                'uid' => self::MEMOSERV_UID,
                'nick' => 'MemoServ',
            ]);

        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::MEMOSERV_UID);

        $bot = new MemoServBot(
            $this->connectionHolder,
            $this->sendNoticePort,
            $uidGenerator,
            self::HOSTNAME,
            'MemoServ',
            'MemoServ',
            'Memo Service',
            $logger,
        );

        $this->connectionHolder->setProtocolModule($module);
        $event = new ServiceIntroductionRequestedEvent('001');
        $bot->onBurstComplete($event);
    }

    #[Test]
    public function getServiceKeyReturnsMemoserv(): void
    {
        self::assertSame('memoserv', $this->bot->getServiceKey());
    }

    #[Test]
    public function getNicknameReturnsConfiguredNick(): void
    {
        self::assertSame('MemoServ', $this->bot->getNickname());
    }
}
