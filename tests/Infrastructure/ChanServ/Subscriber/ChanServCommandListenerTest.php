<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ApplyOutgoingChannelModesPort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChanServDispatchPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\Bot\ChanServBot;
use App\Infrastructure\ChanServ\Subscriber\ChanServCommandListener;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServCommandListener::class)]
final class ChanServCommandListenerTest extends TestCase
{
    private const CHANSERV_UID = '001CS';

    private const CHANSERV_NICK = 'ChanServ';

    private ChanServBot $chanServBot;

    private ChanServDispatchPort&MockObject $chanServService;

    private NetworkUserLookupPort&MockObject $userLookup;

    private ChanServNotifierInterface&MockObject $chanServNotifier;

    private UserMessageTypeResolver $messageTypeResolver;

    private TranslatorInterface&MockObject $translator;

    private RegisteredNickRepositoryInterface&MockObject $nickRepository;

    private LoggerInterface&MockObject $logger;

    private ChanServCommandListener $listener;

    private static function createSenderView(): SenderView
    {
        return new SenderView(
            uid: '001ABC',
            nick: 'TestUser',
            ident: 'user',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
            displayHost: 'user.example.com',
        );
    }

    protected function setUp(): void
    {
        $this->chanServBot = $this->createChanServBotStub();
        $this->chanServService = $this->createMock(ChanServDispatchPort::class);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->chanServNotifier = $this->createMock(ChanServNotifierInterface::class);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->messageTypeResolver = new UserMessageTypeResolver($this->nickRepository);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new ChanServCommandListener(
            $this->chanServBot,
            $this->chanServService,
            $this->userLookup,
            $this->chanServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->nickRepository,
            'en',
            $this->logger,
        );
    }

    private function createChanServBotStub(): ChanServBot
    {
        $connectionHolder = new ActiveConnectionHolder();
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $applyOutgoingChannelModes = $this->createStub(ApplyOutgoingChannelModesPort::class);

        return new ChanServBot(
            $connectionHolder,
            $channelLookup,
            $applyOutgoingChannelModes,
            'services.example.com',
            self::CHANSERV_UID,
            self::CHANSERV_NICK,
        );
    }

    #[Test]
    public function getServiceNameReturnsChanServBotNick(): void
    {
        $this->chanServService->expects(self::never())->method('dispatch');
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(self::CHANSERV_NICK, $this->listener->getServiceName());
    }

    #[Test]
    public function getServiceUidReturnsChanServBotUid(): void
    {
        $this->chanServService->expects(self::never())->method('dispatch');
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(self::CHANSERV_UID, $this->listener->getServiceUid());
    }

    #[Test]
    public function onCommandWithEmptyTextDoesNothing(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->chanServService->expects(self::never())->method('dispatch');
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        $this->listener->onCommand('001ABC', '');
    }

    #[Test]
    public function onCommandWhenSenderNotFoundLogsWarningAndReturns(): void
    {
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('999XXX')
            ->willReturn(null);

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('ChanServ: could not resolve sender UID: 999XXX');

        $this->chanServService->expects(self::never())->method('dispatch');
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->translator->expects(self::never())->method('trans');

        $this->listener->onCommand('999XXX', 'INFO');
    }

    #[Test]
    public function onCommandDispatchesToChanServServiceWithSenderView(): void
    {
        $sender = self::createSenderView();
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($sender);

        $this->chanServService
            ->expects(self::once())
            ->method('dispatch')
            ->with('INFO', $sender);
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        $this->listener->onCommand('001ABC', 'INFO');
    }

    #[Test]
    public function onCommandChannelAlreadyRegisteredSendsExceptionMessageViaNotifier(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);

        $exception = ChannelAlreadyRegisteredException::forChannel('#test');
        $this->chanServService
            ->expects(self::atLeastOnce())
            ->method('dispatch')
            ->with('REGISTER #test', $sender)
            ->willThrowException($exception);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'Channel "#test" is already registered.', 'NOTICE');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('error');

        $this->listener->onCommand('001ABC', 'REGISTER #test');
    }

    #[Test]
    public function onCommandChannelNotRegisteredTranslatesAndSendsViaNotifier(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#mychan'], 'chanserv', 'en')
            ->willReturn('Channel #mychan is not registered.');

        $exception = ChannelNotRegisteredException::forChannel('#mychan');
        $this->chanServService->expects(self::atLeastOnce())->method('dispatch')->willThrowException($exception);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'Channel #mychan is not registered.', 'NOTICE');
        $this->logger->expects(self::never())->method('error');

        $this->listener->onCommand('001ABC', 'ACCESS #mychan LIST');
    }

    #[Test]
    public function onCommandChannelNotRegisteredUsesNickLanguageWhenRegistered(): void
    {
        $sender = self::createSenderView();
        $registeredNick = $this->createStub(RegisteredNick::class);
        $registeredNick->method('getLanguage')->willReturn('es');
        $registeredNick->method('getMessageType')->willReturn('NOTICE');

        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($registeredNick);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#mychan'], 'chanserv', 'es')
            ->willReturn('El canal #mychan no está registrado.');

        $exception = ChannelNotRegisteredException::forChannel('#mychan');
        $this->chanServService->expects(self::atLeastOnce())->method('dispatch')->willThrowException($exception);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'El canal #mychan no está registrado.', 'NOTICE');
        $this->logger->expects(self::never())->method('error');

        $this->listener->onCommand('001ABC', 'ACCESS #mychan LIST');
    }

    #[Test]
    public function onCommandInsufficientAccessTranslatesAndSendsViaNotifier(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with(
                'error.insufficient_access',
                ['%operation%' => 'OP', '%channel%' => '#mychan'],
                'chanserv',
                'en',
            )
            ->willReturn('Insufficient access to OP on channel "#mychan".');

        $exception = InsufficientAccessException::forOperation('#mychan', 'OP');
        $this->chanServService->expects(self::atLeastOnce())->method('dispatch')->willThrowException($exception);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'Insufficient access to OP on channel "#mychan".', 'NOTICE');
        $this->logger->expects(self::never())->method('error');

        $this->listener->onCommand('001ABC', 'OP #mychan user');
    }

    #[Test]
    public function onCommandGenericThrowableLogsErrorAndDoesNotRethrow(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);

        $exception = new RuntimeException('Unexpected error');
        $this->chanServService->expects(self::atLeastOnce())->method('dispatch')->willThrowException($exception);

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'ChanServ dispatch error: Unexpected error',
                self::callback(static fn (array $context): bool => isset($context['exception'], $context['sender'])
                        && '001ABC' === $context['sender']),
            );

        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->translator->expects(self::never())->method('trans');

        $this->listener->onCommand('001ABC', 'SOME CMD');
    }
}
