<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\NickServService;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\SendNoticePort;
use App\Domain\NickServ\Exception\InvalidCredentialsException;
use App\Domain\NickServ\Exception\NickAlreadyRegisteredException;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\NickServ\Bot\NickServBot;
use App\Infrastructure\NickServ\Subscriber\NickServCommandListener;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[CoversClass(NickServCommandListener::class)]
final class NickServCommandListenerTest extends TestCase
{
    private const SENDER_UID = '001ABC';

    private NickServBot $nickServBot;

    private NickServService $nickServService;

    private NetworkUserLookupPort&MockObject $userLookup;

    private SendNoticePort&MockObject $sendNotice;

    private UserMessageTypeResolver $messageTypeResolver;

    private NickServNotifierInterface&MockObject $nickServNotifier;

    private LoggerInterface&MockObject $logger;

    private NickServCommandListener $listener;

    private static function senderView(): SenderView
    {
        return new SenderView(
            uid: self::SENDER_UID,
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example',
            cloakedHost: 'cloak.example',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );
    }

    protected function setUp(): void
    {
        $this->nickServBot = new NickServBot(
            new ActiveConnectionHolder(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(SendNoticePort::class),
            $this->createStub(\App\Application\NickServ\PendingNickRestoreRegistryInterface::class),
            $this->createStub(\App\Domain\IRC\LocalUserModeSyncInterface::class),
            'services.example.com',
            '001NS',
            'NickServ',
        );

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $this->nickServNotifier = $this->createMock(NickServNotifierInterface::class);
        $this->messageTypeResolver = new UserMessageTypeResolver($nickRepository);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $this->nickServService = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry([]),
            $nickRepository,
            $this->nickServNotifier,
            $this->messageTypeResolver,
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            'en',
            'UTC',
        );

        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->sendNotice = $this->createMock(SendNoticePort::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new NickServCommandListener(
            $this->nickServBot,
            $this->nickServService,
            $this->userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->logger,
        );
    }

    #[Test]
    public function getServiceNameReturnsBotNick(): void
    {
        self::assertSame('NickServ', $this->listener->getServiceName());
    }

    #[Test]
    public function getServiceUidReturnsBotUid(): void
    {
        self::assertSame('001NS', $this->listener->getServiceUid());
    }

    #[Test]
    public function onCommandDoesNothingWhenTextIsEmpty(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');

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
            ->with('NickServ: could not resolve sender UID: ' . self::SENDER_UID);

        $this->nickServNotifier->expects(self::never())->method('sendMessage');

        $this->listener->onCommand(self::SENDER_UID, 'IDENTIFY secret');
    }

    #[Test]
    public function onCommandDispatchesToNickServServiceWhenSenderFound(): void
    {
        $sender = self::senderView();
        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn($sender);

        $this->nickServNotifier
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, self::anything(), 'NOTICE');

        $this->listener->onCommand(self::SENDER_UID, 'IDENTIFY secret');
    }

    #[Test]
    public function onCommandSendsNoticeOnNickAlreadyRegisteredException(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $throwCommand = $this->createThrowCommand('REGISTER', new NickAlreadyRegisteredException('TestNick'));
        $this->nickServService = $this->createNickServServiceWithCommands([$throwCommand]);
        $this->listener = new NickServCommandListener(
            $this->nickServBot,
            $this->nickServService,
            $this->userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->logger,
        );

        $this->sendNotice
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'Nickname "TestNick" is already registered.', 'NOTICE');

        $this->listener->onCommand(self::SENDER_UID, 'REGISTER pass');
    }

    #[Test]
    public function onCommandSendsNoticeOnInvalidCredentialsException(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $throwCommand = $this->createThrowCommand('IDENTIFY', new InvalidCredentialsException());
        $this->nickServService = $this->createNickServServiceWithCommands([$throwCommand]);
        $this->listener = new NickServCommandListener(
            $this->nickServBot,
            $this->nickServService,
            $this->userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->logger,
        );

        $this->sendNotice
            ->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'Invalid nickname or password.', 'NOTICE');

        $this->listener->onCommand(self::SENDER_UID, 'IDENTIFY wrong');
    }

    #[Test]
    public function onCommandLogsErrorOnGenericThrowable(): void
    {
        $sender = self::senderView();
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $throwCommand = $this->createThrowCommand('LIST', new RuntimeException('Unexpected error.'));
        $this->nickServService = $this->createNickServServiceWithCommands([$throwCommand]);
        $this->listener = new NickServCommandListener(
            $this->nickServBot,
            $this->nickServService,
            $this->userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->logger,
        );

        $this->sendNotice->expects(self::never())->method('sendMessage');

        $this->logger
            ->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('NickServ dispatch error:'),
                self::callback(static fn (array $context): bool => isset($context['exception'], $context['sender'], $context['text'])),
            );

        $this->listener->onCommand(self::SENDER_UID, 'LIST');
    }

    private function createThrowCommand(string $name, Throwable $e): NickServCommandInterface
    {
        return new class($name, $e) implements NickServCommandInterface {
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

            public function execute(NickServContext $context): void
            {
                throw $this->exception;
            }
        };
    }

    private function createNickServServiceWithCommands(array $commands): NickServService
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry($commands),
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            'en',
            'UTC',
        );
    }
}
