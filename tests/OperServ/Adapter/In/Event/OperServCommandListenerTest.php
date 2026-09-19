<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\OperServ\Adapter\In\Event\OperServCommandListener;
use App\OperServ\Adapter\In\Irc\Bot\OperServBot;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandLogSanitizer;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Adapter\In\Irc\OperServService;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Port\Out\ServiceUserPreferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[CoversClass(OperServCommandListener::class)]
#[CoversClass(OperServCommandLogSanitizer::class)]
final class OperServCommandListenerTest extends TestCase
{
    private const string SENDER_UID = '001ABC';

    #[Test]
    public function commandLogSanitizerCannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(OperServCommandLogSanitizer::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        $constructor->invoke($reflection->newInstanceWithoutConstructor());
    }

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

    private static function createOperServService(OperServCommandRegistry $registry, OperServNotifierInterface $notifier): OperServService
    {
        $preferences = self::createStub(UserMessagePreferenceQuery::class);
        $preferences->method('prefersPrivateMessages')->willReturn(false);
        $language = self::createStub(UserLanguageQuery::class);
        $language->method('resolveFromAccount')->willReturn('en');

        return new OperServService(
            $registry,
            self::createStub(NickAccountQuery::class),
            $language,
            $preferences,
            $notifier,
            self::createStub(TranslatorInterface::class),
            self::createServiceNicks(),
            self::createStub(OperatorAuthorizationQuery::class),
            'en',
            'UTC',
        );
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
        $operServBot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);

        $operServService = self::createOperServService(new OperServCommandRegistry([]), $operServNotifier);

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
        $operServBot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);

        $operServService = self::createOperServService(new OperServCommandRegistry([]), $operServNotifier);

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
        $operServBot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $operServNotifier = $this->createMock(OperServNotifierInterface::class);
        $operServNotifier->expects(self::never())->method('sendMessage');

        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);

        $operServService = self::createOperServService(new OperServCommandRegistry([]), $operServNotifier);

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
        $operServBot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);

        $operServService = self::createOperServService(new OperServCommandRegistry([]), $operServNotifier);

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
        $operServBot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $operServNotifier = $this->createMock(OperServNotifierInterface::class);
        $operServNotifier->expects(self::once())->method('sendMessage')->with(self::SENDER_UID, self::anything(), 'NOTICE');

        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);

        $operServService = self::createOperServService(new OperServCommandRegistry([]), $operServNotifier);

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
        $operServBot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $operServNotifier = $this->createMock(OperServNotifierInterface::class);
        $operServNotifier->expects(self::once())->method('sendMessage');

        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);

        $operServService = self::createOperServService(new OperServCommandRegistry([]), $operServNotifier);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug')->with(
            'OperServ: command from {nick} [{uid}]: {command}',
            self::callback(static fn (array $context): bool => isset(
                $context['nick'],
                $context['uid'],
                $context['command']
            ) && 'TestOper' === $context['nick'] && self::SENDER_UID === $context['uid'] && 'RAW' === $context['command'] && !isset($context['text']))
        );

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'RAW OPAQUE reset-token');
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

        $throwCommand = self::createThrowCommand('RAW', new RuntimeException('reset-token must not be logged'));
        $operServNotifier = self::createStub(OperServNotifierInterface::class);
        $messageTypeResolver = self::createStub(ServiceUserPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);

        $operServService = self::createOperServService(new OperServCommandRegistry([$throwCommand]), $operServNotifier);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::atLeastOnce())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $sendNotice = self::createStub(SendNoticePort::class);
        $userMessageTypeResolver = $messageTypeResolver;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'OperServ dispatch error',
            self::callback(static fn (array $context): bool => isset($context['exception_class'], $context['sender'], $context['command'])
                && RuntimeException::class === $context['exception_class']
                && 'RAW' === $context['command']
                && !isset($context['text'], $context['exception']))
        );

        $listener = new OperServCommandListener(
            $operServBot,
            $operServService,
            $userLookup,
            $sendNotice,
            $userMessageTypeResolver,
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'RAW OPAQUE reset-token');
    }

    #[Test]
    public function loggingNeverRetainsRawOrGlobalPayloads(): void
    {
        self::assertSame('RAW', OperServCommandLogSanitizer::commandName('RAW OPAQUE reset-token'));
        self::assertSame('GLOBAL', OperServCommandLogSanitizer::commandName('GLOBAL * NOTICE private-secret'));
        self::assertSame('UNKNOWN', OperServCommandLogSanitizer::commandName('private-secret payload'));
    }
}
