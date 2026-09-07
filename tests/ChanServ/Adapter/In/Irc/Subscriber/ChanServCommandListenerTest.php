<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ApplyOutgoingChannelModesPort;
use App\Application\Port\EventBusInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceChannelRegistrationPort;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\Bot\ChanServBot;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface as AuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationContextInterface as AuthorizationContextInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\ChanServUserPresentationPreferences;
use App\ChanServ\Adapter\In\Irc\Subscriber\ChanServCommandListener;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Shared\Application\ServiceNicknameRegistry;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[CoversClass(ChanServCommandListener::class)]
final class ChanServCommandListenerTest extends TestCase
{
    private const string CHANSERV_UID = '001CS';

    private const string CHANSERV_NICK = 'ChanServ';

    private ChanServBot $chanServBot;

    private MockObject&NetworkUserLookupPort $userLookup;

    private ChanServNotifierInterface&MockObject $chanServNotifier;

    private ChanServUserPresentationPreferences $messageTypeResolver;

    private MockObject&TranslatorInterface $translator;

    private ChanUserAccountPort&MockObject $accountPort;

    private LoggerInterface&MockObject $logger;

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
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->chanServNotifier = $this->createMock(ChanServNotifierInterface::class);
        $this->accountPort = $this->createMock(ChanUserAccountPort::class);
        $messageTypeResolver = $this->createStub(ChanServUserPresentationPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $this->messageTypeResolver = $messageTypeResolver;
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createChanServBotStub(): ChanServBot
    {
        $connectionHolder = new ActiveConnectionHolder();
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $applyOutgoingChannelModes = $this->createStub(ApplyOutgoingChannelModesPort::class);
        $channelRegistration = $this->createStub(ServiceChannelRegistrationPort::class);
        $sendNoticePort = $this->createStub(SendNoticePort::class);
        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::CHANSERV_UID);

        $bot = new ChanServBot(
            $connectionHolder,
            $channelLookup,
            $applyOutgoingChannelModes,
            $channelRegistration,
            $sendNoticePort,
            $uidGenerator,
            'services.example.com',
            self::CHANSERV_NICK,
        );

        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        return $bot;
    }

    /**
     * @param iterable<ChanServCommandInterface> $commands
     */
    private function createChanServService(iterable $commands): ChanServService
    {
        return new ChanServService(
            commandRegistry: new ChanServCommandRegistry($commands),
            channelRepository: $this->createStub(RegisteredChannelRepositoryInterface::class),
            accountPort: $this->createStub(ChanUserAccountPort::class),
            preferences: $this->messageTypeResolver,
            notifier: $this->chanServNotifier,
            translator: $this->createStub(TranslationInterface::class),
            channelLookup: $this->createStub(ChannelLookupPort::class),
            modeSupportProvider: $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            userLookup: $this->userLookup,
            authorizationContext: $this->createStub(AuthorizationContextInterface::class),
            authorizationChecker: $this->createStub(AuthorizationCheckerInterface::class),
            eventDispatcher: $this->createStub(EventBusInterface::class),
            serviceNicks: new ServiceNicknameRegistry([]),
            defaultLanguage: 'en',
            defaultTimezone: 'UTC',
            logger: new NullLogger(),
        );
    }

    /**
     * @param iterable<ChanServCommandInterface> $commands
     */
    private function createListener(iterable $commands = []): ChanServCommandListener
    {
        return new ChanServCommandListener(
            $this->chanServBot,
            $this->createChanServService($commands),
            $this->userLookup,
            $this->chanServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->accountPort,
            'en',
            $this->logger,
        );
    }

    private function createThrowCommand(string $name, Throwable $exception): ChanServCommandInterface
    {
        return new class($name, $exception) implements ChanServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly Throwable $exception,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return '';
            }

            public function getHelpKey(): string
            {
                return '';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return '';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): never
            {
                throw $this->exception;
            }
        };
    }

    private function createDummyCommand(string $name, Closure $callback): ChanServCommandInterface
    {
        return new class($name, $callback) implements ChanServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly Closure $callback,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return '';
            }

            public function getHelpKey(): string
            {
                return '';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return '';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $context): void
            {
                ($this->callback)($context);
            }
        };
    }

    #[Test]
    public function getServiceNameReturnsChanServBotNick(): void
    {
        $listener = $this->createListener();
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->accountPort->expects(self::never())->method('findAccountByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(self::CHANSERV_NICK, $listener->getServiceName());
    }

    #[Test]
    public function getServiceUidReturnsChanServBotUid(): void
    {
        $listener = $this->createListener();
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->accountPort->expects(self::never())->method('findAccountByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(self::CHANSERV_UID, $listener->getServiceUid());
    }

    #[Test]
    public function onCommandWithEmptyTextDoesNothing(): void
    {
        $listener = $this->createListener();
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->accountPort->expects(self::never())->method('findAccountByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        $listener->onCommand('001ABC', '');
    }

    #[Test]
    public function onCommandWhenSenderNotFoundLogsWarningAndReturns(): void
    {
        $listener = $this->createListener();
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('999XXX')
            ->willReturn(null);

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('ChanServ: could not resolve sender UID: 999XXX');

        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->accountPort->expects(self::never())->method('findAccountByNick');
        $this->translator->expects(self::never())->method('trans');

        $listener->onCommand('999XXX', 'INFO');
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

        $executed = false;
        $command = $this->createDummyCommand('INFO', static function (ChanServContext $context) use (&$executed, $sender): void {
            $executed = true;
            self::assertSame($sender->uid, $context->sender?->uid);
            self::assertSame('INFO', $context->command);
        });

        $listener = $this->createListener([$command]);
        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->accountPort->expects(self::never())->method('findAccountByNick');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('warning');

        $listener->onCommand('001ABC', 'INFO');

        self::assertTrue($executed);
    }

    #[Test]
    public function onCommandChannelAlreadyRegisteredSendsExceptionMessageViaNotifier(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->accountPort->expects(self::never())->method('findAccountByNick');

        $exception = ChannelAlreadyRegisteredException::forChannel('#test');
        $command = $this->createThrowCommand('REGISTER', $exception);
        $listener = $this->createListener([$command]);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'Channel "#test" is already registered.', 'NOTICE');
        $this->translator->expects(self::never())->method('trans');
        $this->logger->expects(self::never())->method('error');

        $listener->onCommand('001ABC', 'REGISTER #test');
    }

    #[Test]
    public function onCommandChannelNotRegisteredTranslatesAndSendsViaNotifier(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->accountPort->expects(self::atLeastOnce())->method('findAccountByNick')->with('TestUser')->willReturn(null);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#mychan', '%bot%' => 'ChanServ'], 'chanserv', 'en')
            ->willReturn('Channel #mychan is not registered.');

        $exception = ChannelNotRegisteredException::forChannel('#mychan');
        $command = $this->createThrowCommand('ACCESS', $exception);
        $listener = $this->createListener([$command]);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'Channel #mychan is not registered.', 'NOTICE');
        $this->logger->expects(self::never())->method('error');

        $listener->onCommand('001ABC', 'ACCESS #mychan LIST');
    }

    #[Test]
    public function onCommandChannelNotRegisteredUsesNickLanguageWhenRegistered(): void
    {
        $sender = self::createSenderView();
        $registeredNick = new ChanAccountView(1, 'TestUser', 'es');

        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->accountPort->expects(self::atLeastOnce())->method('findAccountByNick')->with('TestUser')->willReturn($registeredNick);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#mychan', '%bot%' => 'ChanServ'], 'chanserv', 'es')
            ->willReturn('El canal #mychan no está registrado.');

        $exception = ChannelNotRegisteredException::forChannel('#mychan');
        $command = $this->createThrowCommand('ACCESS', $exception);
        $listener = $this->createListener([$command]);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'El canal #mychan no está registrado.', 'NOTICE');
        $this->logger->expects(self::never())->method('error');

        $listener->onCommand('001ABC', 'ACCESS #mychan LIST');
    }

    #[Test]
    public function onCommandInsufficientAccessTranslatesAndSendsViaNotifier(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);
        $this->accountPort->expects(self::atLeastOnce())->method('findAccountByNick')->with('TestUser')->willReturn(null);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with(
                'error.insufficient_access',
                ['%operation%' => 'OP', '%channel%' => '#mychan', '%bot%' => 'ChanServ'],
                'chanserv',
                'en',
            )
            ->willReturn('Insufficient access to OP on channel "#mychan".');

        $exception = InsufficientAccessException::forOperation('#mychan', 'OP');
        $command = $this->createThrowCommand('OP', $exception);
        $listener = $this->createListener([$command]);

        $this->chanServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', 'Insufficient access to OP on channel "#mychan".', 'NOTICE');
        $this->logger->expects(self::never())->method('error');

        $listener->onCommand('001ABC', 'OP #mychan user');
    }

    #[Test]
    public function onCommandGenericThrowableLogsErrorAndDoesNotRethrow(): void
    {
        $sender = self::createSenderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001ABC')->willReturn($sender);

        $exception = new RuntimeException('Unexpected error');
        $command = $this->createThrowCommand('SOME', $exception);
        $listener = $this->createListener([$command]);

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                'ChanServ dispatch error: Unexpected error',
                self::callback(static fn (array $context): bool => isset($context['exception'], $context['sender'])
                        && '001ABC' === $context['sender']),
            );

        $this->chanServNotifier->expects(self::never())->method('sendMessage');
        $this->accountPort->expects(self::never())->method('findAccountByNick');
        $this->translator->expects(self::never())->method('trans');

        $listener->onCommand('001ABC', 'SOME CMD');
    }
}
