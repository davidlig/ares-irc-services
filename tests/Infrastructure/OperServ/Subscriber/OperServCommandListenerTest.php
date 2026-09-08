<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\OperServ\Subscriber;

use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\OperServService;
use App\Application\OperServ\Port\Out\ServiceUserPreferences;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\EventBusInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\TranslationInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Infrastructure\OperServ\Bot\OperServBot;
use App\Infrastructure\OperServ\Subscriber\OperServCommandListener;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

#[CoversClass(OperServCommandListener::class)]
final class OperServCommandListenerTest extends TestCase
{
    private const string SENDER_UID = '001ABC';

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

    private static function createAccessHelper(): IrcopAccessHelper
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = self::createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = self::createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private static function createServiceNicks(): ServiceNicknameRegistry
    {
        $nickservProvider = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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

    private static function createThrowCommand(string $name, Throwable $e): OperServCommandInterface
    {
        return new class($name, $e) implements OperServCommandInterface {
            public function __construct(
                private readonly string $commandName,
                private readonly Throwable $exception,
            ) {}

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

    #[Test]
    public function getServiceNameReturnsBotNick(): void
    {
        $uidGenerator = self::createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn('001OS');

        $operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            self::createStub(NetworkUserLookupPort::class),
            self::createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            'OperServ',
        );
        $operServBot->onBurstComplete(new NetworkBurstCompleteEvent(
            self::createStub(ConnectionInterface::class),
            '001',
        ));

        $nickRepository = self::createStub(RegisteredNickRepositoryInterface::class);
        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $translator = self::createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);
        $accessHelper = self::createAccessHelper();

        $operServService = new OperServService(
            new OperServCommandRegistry([]),
            $nickRepository,
            $messageTypeResolver,
            $operServNotifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            self::createServiceNicks(),
            self::createStub(AuthorizationContextInterface::class),
            self::createStub(AuthorizationCheckerInterface::class),
            self::createStub(EventBusInterface::class),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );

        $userLookup = self::createStub(NetworkUserLookupPort::class);
        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = self::createStub(LoggerInterface::class);

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        self::assertSame('OperServ', $listener->getServiceName());
        self::assertSame($sendNotice, $listener->getSendNotice());
        self::assertSame($userMessageTypeResolver, $listener->getMessageTypeResolver());
    }

    #[Test]
    public function getServiceUidReturnsBotUid(): void
    {
        $uidGenerator = self::createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn('001OS');

        $operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            self::createStub(NetworkUserLookupPort::class),
            self::createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            'OperServ',
        );
        $operServBot->onBurstComplete(new NetworkBurstCompleteEvent(
            self::createStub(ConnectionInterface::class),
            '001',
        ));

        $nickRepository = self::createStub(RegisteredNickRepositoryInterface::class);
        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $translator = self::createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);
        $accessHelper = self::createAccessHelper();

        $operServService = new OperServService(
            new OperServCommandRegistry([]),
            $nickRepository,
            $messageTypeResolver,
            $operServNotifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            self::createServiceNicks(),
            self::createStub(AuthorizationContextInterface::class),
            self::createStub(AuthorizationCheckerInterface::class),
            self::createStub(EventBusInterface::class),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );

        $userLookup = self::createStub(NetworkUserLookupPort::class);
        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = self::createStub(LoggerInterface::class);

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        self::assertSame('001OS', $listener->getServiceUid());
    }

    #[Test]
    public function onCommandDoesNothingWhenTextIsEmpty(): void
    {
        $uidGenerator = self::createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn('001OS');

        $operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            self::createStub(NetworkUserLookupPort::class),
            self::createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            'OperServ',
        );
        $operServBot->onBurstComplete(new NetworkBurstCompleteEvent(
            self::createStub(ConnectionInterface::class),
            '001',
        ));

        $nickRepository = self::createStub(RegisteredNickRepositoryInterface::class);
        $operServNotifier = $this->createMock(OperServNotifierInterface::class);
        $operServNotifier->expects(self::never())->method('sendMessage');

        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $translator = self::createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);
        $accessHelper = self::createAccessHelper();

        $operServService = new OperServService(
            new OperServCommandRegistry([]),
            $nickRepository,
            $messageTypeResolver,
            $operServNotifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            self::createServiceNicks(),
            self::createStub(AuthorizationContextInterface::class),
            self::createStub(AuthorizationCheckerInterface::class),
            self::createStub(EventBusInterface::class),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('findByUid');

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendMessage');

        $userMessageTypeResolver = $messageTypeResolver;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, '');
    }

    #[Test]
    public function onCommandLogsWarningAndReturnsWhenSenderNotFound(): void
    {
        $uidGenerator = self::createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn('001OS');

        $operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            self::createStub(NetworkUserLookupPort::class),
            self::createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            'OperServ',
        );
        $operServBot->onBurstComplete(new NetworkBurstCompleteEvent(
            self::createStub(ConnectionInterface::class),
            '001',
        ));

        $nickRepository = self::createStub(RegisteredNickRepositoryInterface::class);
        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $translator = self::createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);
        $accessHelper = self::createAccessHelper();

        $operServService = new OperServService(
            new OperServCommandRegistry([]),
            $nickRepository,
            $messageTypeResolver,
            $operServNotifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            self::createServiceNicks(),
            self::createStub(AuthorizationContextInterface::class),
            self::createStub(AuthorizationCheckerInterface::class),
            self::createStub(EventBusInterface::class),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with(self::SENDER_UID)->willReturn(null);

        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('OperServ: could not resolve sender UID: ' . self::SENDER_UID);

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'HELP');
    }

    #[Test]
    public function onCommandDispatchesToOperServServiceWhenSenderFound(): void
    {
        $sender = self::senderView();

        $uidGenerator = self::createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn('001OS');

        $operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            self::createStub(NetworkUserLookupPort::class),
            self::createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            'OperServ',
        );
        $operServBot->onBurstComplete(new NetworkBurstCompleteEvent(
            self::createStub(ConnectionInterface::class),
            '001',
        ));

        $nickRepository = self::createStub(RegisteredNickRepositoryInterface::class);
        $operServNotifier = $this->createMock(OperServNotifierInterface::class);
        $operServNotifier->expects(self::once())->method('sendMessage')->with(self::SENDER_UID, self::anything(), 'NOTICE');

        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $translator = self::createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);
        $accessHelper = self::createAccessHelper();

        $operServService = new OperServService(
            new OperServCommandRegistry([]),
            $nickRepository,
            $messageTypeResolver,
            $operServNotifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            self::createServiceNicks(),
            self::createStub(AuthorizationContextInterface::class),
            self::createStub(AuthorizationCheckerInterface::class),
            self::createStub(EventBusInterface::class),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = self::createStub(LoggerInterface::class);

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'HELP');
    }

    #[Test]
    public function onCommandLogsCommandAtDebugLevel(): void
    {
        $sender = self::senderView();

        $uidGenerator = self::createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn('001OS');

        $operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            self::createStub(NetworkUserLookupPort::class),
            self::createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            'OperServ',
        );
        $operServBot->onBurstComplete(new NetworkBurstCompleteEvent(
            self::createStub(ConnectionInterface::class),
            '001',
        ));

        $nickRepository = self::createStub(RegisteredNickRepositoryInterface::class);
        $operServNotifier = $this->createMock(OperServNotifierInterface::class);
        $operServNotifier->expects(self::once())->method('sendMessage');

        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $translator = self::createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);
        $accessHelper = self::createAccessHelper();

        $operServService = new OperServService(
            new OperServCommandRegistry([]),
            $nickRepository,
            $messageTypeResolver,
            $operServNotifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            self::createServiceNicks(),
            self::createStub(AuthorizationContextInterface::class),
            self::createStub(AuthorizationCheckerInterface::class),
            self::createStub(EventBusInterface::class),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            'OperServ: command from {nick} [{uid}]: {text}',
            self::callback(static fn (array $context): bool => isset(
                $context['nick'],
                $context['uid'],
                $context['text']
            ) && 'TestOper' === $context['nick'] && self::SENDER_UID === $context['uid'])
        );

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'HELP');
    }

    #[Test]
    public function onCommandCatchesExceptionAndLogsError(): void
    {
        $sender = self::senderView();

        $uidGenerator = self::createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn('001OS');

        $operServBot = new OperServBot(
            new ActiveConnectionHolder(),
            self::createStub(NetworkUserLookupPort::class),
            self::createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            'OperServ',
        );

        $throwCommand = self::createThrowCommand('TEST', new RuntimeException('Test error.'));
        $nickRepository = self::createStub(RegisteredNickRepositoryInterface::class);
        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $translator = self::createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = self::createAccessHelper();

        $operServService = new OperServService(
            new OperServCommandRegistry([$throwCommand]),
            $nickRepository,
            $messageTypeResolver,
            $operServNotifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            self::createServiceNicks(),
            self::createStub(AuthorizationContextInterface::class),
            self::createStub(AuthorizationCheckerInterface::class),
            self::createStub(EventBusInterface::class),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            self::stringContains('OperServ dispatch error:'),
            self::callback(static fn (array $context): bool => isset($context['exception'], $context['sender'], $context['text']))
        );

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'TEST');
    }
}
