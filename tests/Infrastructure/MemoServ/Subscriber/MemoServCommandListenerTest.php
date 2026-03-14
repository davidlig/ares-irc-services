<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemoServ\Subscriber;

use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\MemoServ\MemoServService;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\MemoServ\Bot\MemoServBot;
use App\Infrastructure\MemoServ\Subscriber\MemoServCommandListener;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[CoversClass(MemoServCommandListener::class)]
final class MemoServCommandListenerTest extends TestCase
{
    private const SENDER_UID = '001ABC';

    private const MEMOSERV_UID = '001MS';

    private MemoServBot $memoServBot;

    private MemoServService $memoServService;

    private NetworkUserLookupPort&MockObject $userLookup;

    private MemoServNotifierInterface&MockObject $memoServNotifier;

    private UserMessageTypeResolver $messageTypeResolver;

    private TranslatorInterface&MockObject $translator;

    private RegisteredNickRepositoryInterface&MockObject $nickRepository;

    private LoggerInterface&MockObject $logger;

    private MemoServCommandListener $listener;

    private static function senderView(): SenderView
    {
        return new SenderView(
            uid: self::SENDER_UID,
            nick: 'TestUser',
            ident: 'test',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
        );
    }

    protected function setUp(): void
    {
        $this->memoServBot = new MemoServBot(
            new ActiveConnectionHolder(),
            'services.example.com',
            self::MEMOSERV_UID,
        );

        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->memoServNotifier = $this->createMock(MemoServNotifierInterface::class);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->messageTypeResolver = new UserMessageTypeResolver($this->nickRepository);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->memoServService = new MemoServService(
            new MemoServCommandRegistry([]),
            $this->nickRepository,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            'en',
            'UTC',
            $this->logger,
        );

        $this->listener = new MemoServCommandListener(
            $this->memoServBot,
            $this->memoServService,
            $this->userLookup,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->nickRepository,
            'en',
            $this->logger,
        );
    }

    #[Test]
    public function getServiceNameReturnsMemoServBotNick(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->memoServNotifier->expects(self::never())->method('sendMessage');
        $this->translator->expects(self::never())->method('trans');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->nickRepository->expects(self::never())->method('findById');
        $this->logger->expects(self::never())->method('warning');
        self::assertSame('MemoServ', $this->listener->getServiceName());
    }

    #[Test]
    public function getServiceUidReturnsMemoServBotUid(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->memoServNotifier->expects(self::never())->method('sendMessage');
        $this->translator->expects(self::never())->method('trans');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->nickRepository->expects(self::never())->method('findById');
        $this->logger->expects(self::never())->method('warning');
        self::assertSame(self::MEMOSERV_UID, $this->listener->getServiceUid());
    }

    #[Test]
    public function onCommandWithEmptyTextDoesNotDispatch(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->memoServNotifier->expects(self::never())->method('sendMessage');
        $this->translator->expects(self::never())->method('trans');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->logger->expects(self::never())->method('warning');

        $this->listener->onCommand(self::SENDER_UID, '');
    }

    #[Test]
    public function onCommandWhenSenderNotFoundLogsWarningAndDoesNotDispatch(): void
    {
        $this->memoServNotifier->expects(self::never())->method('sendMessage');
        $this->translator->expects(self::never())->method('trans');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(null);

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('MemoServ: could not resolve sender UID: ' . self::SENDER_UID);

        $this->listener->onCommand(self::SENDER_UID, 'LIST');
    }

    #[Test]
    public function onCommandDispatchesToMemoServServiceWithSender(): void
    {
        $sender = self::senderView();
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $captureHandler = $this->createCaptureContextHandler($contextHolder);
        $this->memoServService = new MemoServService(
            new MemoServCommandRegistry([$captureHandler]),
            $this->nickRepository,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            'en',
            'UTC',
            $this->logger,
        );
        $this->listener = new MemoServCommandListener(
            $this->memoServBot,
            $this->memoServService,
            $this->userLookup,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->nickRepository,
            'en',
            $this->logger,
        );

        $this->userLookup
            ->expects(self::atLeastOnce())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->translator->expects(self::never())->method('trans');
        $this->memoServNotifier->expects(self::never())->method('sendMessage');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $this->listener->onCommand(self::SENDER_UID, 'SEND someone hello');

        self::assertInstanceOf(MemoServContext::class, $contextHolder->context);
        self::assertSame($sender, $contextHolder->context->sender);
        self::assertSame('SEND', $contextHolder->context->command);
        self::assertSame(['someone', 'hello'], $contextHolder->context->args);
    }

    #[Test]
    public function onCommandWhenChannelNotRegisteredSendsTranslatedError(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('es');
        $account->method('getMessageType')->willReturn('NOTICE');
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#test'], 'memoserv', 'es')
            ->willReturn('El canal #test no está registrado.');

        $this->memoServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'El canal #test no está registrado.', 'NOTICE');
        $this->logger->expects(self::never())->method('warning');

        $throwHandler = $this->createThrowHandlerForCommand('SEND', ChannelNotRegisteredException::forChannel('#test'));
        $this->memoServService = new MemoServService(
            new MemoServCommandRegistry([$throwHandler]),
            $this->nickRepository,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            'en',
            'UTC',
            $this->logger,
        );
        $this->listener = new MemoServCommandListener(
            $this->memoServBot,
            $this->memoServService,
            $this->userLookup,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->nickRepository,
            'en',
            $this->logger,
        );

        $this->listener->onCommand(self::SENDER_UID, 'SEND #test hi');
    }

    #[Test]
    public function onCommandWhenChannelNotRegisteredUsesDefaultLanguageWhenAccountNotFound(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#other'], 'memoserv', 'en')
            ->willReturn('Channel #other is not registered.');

        $this->memoServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'Channel #other is not registered.', 'NOTICE');
        $this->logger->expects(self::never())->method('warning');

        $throwHandler = $this->createThrowHandlerForCommand('SEND', ChannelNotRegisteredException::forChannel('#other'));
        $this->memoServService = new MemoServService(
            new MemoServCommandRegistry([$throwHandler]),
            $this->nickRepository,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            'en',
            'UTC',
            $this->logger,
        );
        $this->listener = new MemoServCommandListener(
            $this->memoServBot,
            $this->memoServService,
            $this->userLookup,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->nickRepository,
            'en',
            $this->logger,
        );

        $this->listener->onCommand(self::SENDER_UID, 'SEND #other hi');
    }

    #[Test]
    public function onCommandWhenInsufficientAccessSendsTranslatedError(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('fr');
        $account->method('getMessageType')->willReturn('PRIVMSG');
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);

        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with(
                'error.insufficient_access',
                ['%operation%' => 'SEND', '%channel%' => '#chan'],
                'memoserv',
                'fr',
            )
            ->willReturn('Accès insuffisant pour #chan.');

        $this->memoServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'Accès insuffisant pour #chan.', 'PRIVMSG');
        $this->logger->expects(self::never())->method('warning');

        $throwHandler = $this->createThrowHandlerForCommand('SEND', InsufficientAccessException::forOperation('#chan', 'SEND'));
        $this->memoServService = new MemoServService(
            new MemoServCommandRegistry([$throwHandler]),
            $this->nickRepository,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            'en',
            'UTC',
            $this->logger,
        );
        $this->listener = new MemoServCommandListener(
            $this->memoServBot,
            $this->memoServService,
            $this->userLookup,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->nickRepository,
            'en',
            $this->logger,
        );

        $this->listener->onCommand(self::SENDER_UID, 'SEND #chan hi');
    }

    #[Test]
    public function onCommandWhenOtherThrowableLogsErrorAndDoesNotRethrow(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $exception = new RuntimeException('Something broke');
        $throwHandler = $this->createThrowHandlerForCommand('LIST', $exception);
        $this->memoServService = new MemoServService(
            new MemoServCommandRegistry([$throwHandler]),
            $this->nickRepository,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            'en',
            'UTC',
            $this->logger,
        );
        $this->listener = new MemoServCommandListener(
            $this->memoServBot,
            $this->memoServService,
            $this->userLookup,
            $this->memoServNotifier,
            $this->messageTypeResolver,
            $this->translator,
            $this->nickRepository,
            'en',
            $this->logger,
        );

        $this->logger
            ->expects(self::exactly(2))
            ->method('error')
            ->with(
                'MemoServ dispatch error: Something broke',
                self::callback(static fn (array $context): bool => isset($context['exception'], $context['sender'])
                        && self::SENDER_UID === $context['sender']),
            );

        $this->memoServNotifier->expects(self::never())->method('sendMessage');
        $this->translator->expects(self::never())->method('trans');
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);

        $this->listener->onCommand(self::SENDER_UID, 'LIST');
    }

    private function createCaptureContextHandler(stdClass $contextHolder): MemoServCommandInterface
    {
        return new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'SEND';
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
                return 'dummy.syntax';
            }

            public function getHelpKey(): string
            {
                return 'dummy.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'dummy.short';
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

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };
    }

    private function createThrowHandlerForCommand(string $commandName, Throwable $e): MemoServCommandInterface
    {
        return new class($commandName, $e) implements MemoServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly Throwable $exception,
            ) {
            }

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
                return 'dummy.syntax';
            }

            public function getHelpKey(): string
            {
                return 'dummy.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'dummy.short';
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

            public function execute(MemoServContext $context): void
            {
                throw $this->exception;
            }
        };
    }
}
