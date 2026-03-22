<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\OperServService;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\SendNoticePort;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use App\Infrastructure\OperServ\Bot\OperServBot;
use App\Infrastructure\OperServ\Subscriber\OperServCommandListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[CoversClass(OperServCommandListener::class)]
final class OperServCommandListenerTest extends TestCase
{
    private const string SENDER_UID = '001ABC';

    private OperServBot $operServBot;

    private OperServService $operServService;

    private NetworkUserLookupPort&MockObject $userLookup;

    private SendNoticePort&MockObject $sendNotice;

    private UserMessageTypeResolverInterface $messageTypeResolver;

    private OperServNotifierInterface&MockObject $operServNotifier;

    private LoggerInterface&MockObject $logger;

    private OperServCommandListener $listener;

    private static function senderView(): SenderView
    {
        return new SenderView(
            uid: self::SENDER_UID,
            nick: 'TestOper',
            ident: 'oper',
            hostname: 'oper.example',
            cloakedHost: 'cloak.example',
            ipBase64: 'b3Blcg==',
            isIdentified: false,
            isOper: true,
            serverSid: '001',
        );
    }

    private function createAccessHelper(): IrcopAccessHelper
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    protected function setUp(): void
    {
        $this->operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            'services.example.com',
            '001OS',
            'OperServ',
        );

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $this->operServNotifier = $this->createMock(OperServNotifierInterface::class);
        $this->messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $this->messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);
        $accessHelper = $this->createAccessHelper();

        $this->operServService = new OperServService(
            new OperServCommandRegistry([]),
            $nickRepository,
            $this->operServNotifier,
            $this->messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
        );

        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->sendNotice = $this->createMock(SendNoticePort::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $userMessageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $this->listener = new OperServCommandListener(
            $this->operServBot,
            $this->operServService,
            $this->userLookup,
            $this->sendNotice,
            $userMessageTypeResolver,
            $this->logger,
        );
    }

    #[Test]
    public function getServiceNameReturnsBotNick(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->sendNotice->expects(self::never())->method('sendMessage');
        $this->operServNotifier->expects(self::never())->method('sendMessage');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('debug');
        $this->logger->expects(self::never())->method('error');

        self::assertSame('OperServ', $this->listener->getServiceName());
    }

    #[Test]
    public function getServiceUidReturnsBotUid(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->sendNotice->expects(self::never())->method('sendMessage');
        $this->operServNotifier->expects(self::never())->method('sendMessage');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('debug');
        $this->logger->expects(self::never())->method('error');

        self::assertSame('001OS', $this->listener->getServiceUid());
    }

    #[Test]
    public function onCommandDoesNothingWhenTextIsEmpty(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->sendNotice->expects(self::never())->method('sendMessage');
        $this->operServNotifier->expects(self::never())->method('sendMessage');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('debug');
        $this->logger->expects(self::never())->method('error');

        $this->listener->onCommand(self::SENDER_UID, '');
    }

    #[Test]
    public function onCommandLogsWarningAndReturnsWhenSenderNotFound(): void
    {
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(null);

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with('OperServ: could not resolve sender UID: ' . self::SENDER_UID);

        $this->operServNotifier->expects(self::never())->method('sendMessage');
        $this->sendNotice->expects(self::never())->method('sendMessage');

        $this->listener->onCommand(self::SENDER_UID, 'HELP');
    }

    #[Test]
    public function onCommandDispatchesToOperServServiceWhenSenderFound(): void
    {
        $sender = self::senderView();
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn($sender);

        $this->operServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, self::anything(), 'NOTICE');
        $this->sendNotice->expects(self::never())->method('sendMessage');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $this->listener->onCommand(self::SENDER_UID, 'HELP');
    }

    #[Test]
    #[AllowMockObjectsWithoutExpectations]
    public function onCommandLogsCommandAtDebugLevel(): void
    {
        $sender = self::senderView();
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn($sender);

        $this->logger
            ->expects(self::once())
            ->method('debug')
            ->with(
                'OperServ: command from {nick} [{uid}]: {text}',
                self::callback(static fn (array $context): bool => isset(
                    $context['nick'],
                    $context['uid'],
                    $context['text']
                ) && 'TestOper' === $context['nick'] && self::SENDER_UID === $context['uid'])
            );

        $this->operServNotifier->expects(self::once())->method('sendMessage');

        $this->listener->onCommand(self::SENDER_UID, 'HELP');
    }

    #[Test]
    public function onCommandCatchesExceptionAndLogsError(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $throwCommand = $this->createThrowCommand('TEST', new RuntimeException('Test error.'));
        $operServService = $this->createOperServServiceWithCommands([$throwCommand]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userMessageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $this->listener = new OperServCommandListener(
            $this->operServBot,
            $operServService,
            $this->userLookup,
            $this->sendNotice,
            $userMessageTypeResolver,
            $this->logger,
        );

        $this->sendNotice->expects(self::never())->method('sendMessage');
        $this->operServNotifier->expects(self::never())->method('sendMessage');

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('OperServ dispatch error:'),
                self::callback(static fn (array $context): bool => isset($context['exception'], $context['sender'], $context['text'])),
            );

        $this->listener->onCommand(self::SENDER_UID, 'TEST');
    }

    private function createThrowCommand(string $name, Throwable $e): OperServCommandInterface
    {
        return new class($name, $e) implements OperServCommandInterface {
            public function __construct(
                private readonly string $commandName,
                private readonly Throwable $exception,
            ) {
            }

            public function getName(): string
            {
                return $this->commandName;
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

            public function execute(OperServContext $context): void
            {
                throw $this->exception;
            }
        };
    }

    private function createOperServServiceWithCommands(array $commands): OperServService
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper();

        return new OperServService(
            new OperServCommandRegistry($commands),
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $nickservProvider = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $chanservProvider = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $memoservProvider = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $operservProvider = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([
            $nickservProvider,
            $chanservProvider,
            $memoservProvider,
            $operservProvider,
        ]);
    }
}
