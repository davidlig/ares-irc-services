<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Subscriber;

use App\Application\Port\EventBusInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\TranslationInterface;
use App\Domain\ChanServ\Exception\ChannelNotRegisteredException;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\MemoServ\Adapter\In\Irc\Bot\MemoServBot;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationCheckerInterface;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationContextInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Adapter\In\Irc\MemoServService;
use App\MemoServ\Adapter\In\Irc\Subscriber\MemoServCommandListener;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\Port\Out\ServiceUserPreferences;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[CoversClass(MemoServCommandListener::class)]
final class MemoServCommandListenerTest extends TestCase
{
    private const string SENDER_UID = '001ABC';

    private const string BOT_NICK = 'MemoServ';

    private const string BOT_UID = '001MS';

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

    private function createBot(): MemoServBot
    {
        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::BOT_UID);

        $bot = new MemoServBot(
            new ActiveConnectionHolder(),
            $this->createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            self::BOT_NICK,
        );

        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        return $bot;
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'memoserv';
            }

            public function getNickname(): string
            {
                return 'MemoServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    /**
     * @param iterable<MemoServCommandInterface> $commands
     */
    private function createService(
        iterable $commands,
        ?MemoUserAccountPort $userAccountPort = null,
        ?ServiceUserPreferences $preferences = null,
        ?MemoServNotifierInterface $notifier = null,
    ): MemoServService {
        return new MemoServService(
            commandRegistry: new MemoServCommandRegistry($commands),
            userAccountPort: $userAccountPort ?? $this->createStub(MemoUserAccountPort::class),
            languageResolver: $preferences ?? $this->createStub(ServiceUserPreferences::class),
            notifier: $notifier ?? $this->createStub(MemoServNotifierInterface::class),
            messageTypeResolver: $preferences ?? $this->createStub(ServiceUserPreferences::class),
            translator: $this->createStub(TranslationInterface::class),
            serviceNicks: $this->createServiceNicks(),
            authorizationContext: $this->createStub(MemoAuthorizationContextInterface::class),
            authorizationChecker: $this->createStub(MemoAuthorizationCheckerInterface::class),
            eventDispatcher: $this->createStub(EventBusInterface::class),
            defaultLanguage: 'en',
            defaultTimezone: 'UTC',
            logger: new NullLogger(),
        );
    }

    private function createThrowCommand(string $name, Throwable $e): MemoServCommandInterface
    {
        return new class($name, $e) implements MemoServCommandInterface {
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
                return 'throw.syntax';
            }

            public function getHelpKey(): string
            {
                return 'throw.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'throw.short';
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

    #[Test]
    public function returnsServiceNameAndUid(): void
    {
        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([]),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(ServiceUserPreferences::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MemoUserAccountPort::class),
            'en',
        );

        self::assertSame(self::BOT_NICK, $listener->getServiceName());
        self::assertSame(self::BOT_UID, $listener->getServiceUid());
    }

    #[Test]
    public function doesNothingWhenTextIsEmpty(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('findByUid');

        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([]),
            $userLookup,
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(ServiceUserPreferences::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MemoUserAccountPort::class),
            'en',
        );

        $listener->onCommand(self::SENDER_UID, '');
    }

    #[Test]
    public function logsWarningWhenSenderNotFound(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with('MemoServ: could not resolve sender UID: ' . self::SENDER_UID);

        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([]),
            $userLookup,
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(ServiceUserPreferences::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MemoUserAccountPort::class),
            'en',
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'HELP');
    }

    #[Test]
    public function dispatchesSuccessfully(): void
    {
        $state = new stdClass();
        $state->executed = false;
        $cmd = new class($state) implements MemoServCommandInterface {
            public function __construct(private stdClass $state) {}

            public function getName(): string
            {
                return 'SUCCESS';
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
                return 's.syntax';
            }

            public function getHelpKey(): string
            {
                return 's.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 's.short';
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
                $this->state->executed = true;
            }
        };

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(self::senderView());

        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([$cmd]),
            $userLookup,
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(ServiceUserPreferences::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MemoUserAccountPort::class),
            'en',
        );

        $listener->onCommand(self::SENDER_UID, 'SUCCESS');
        self::assertTrue($state->executed);
    }

    #[Test]
    public function handlesChannelNotRegisteredExceptionWithAccountLanguageAndPrivmsg(): void
    {
        $cmd = $this->createThrowCommand('FAIL', ChannelNotRegisteredException::forChannel('#channel'));

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(self::senderView());

        $preferences = $this->createMock(ServiceUserPreferences::class);
        $preferences->expects(self::once())
            ->method('prefersPrivateMessages')
            ->with('TestUser')
            ->willReturn(true);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())
            ->method('findAccountByNick')
            ->with('TestUser')
            ->willReturn(new MemoAccountView(1, 'TestUser', 'fr'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#channel', '%bot%' => self::BOT_NICK], 'memoserv', 'fr')
            ->willReturn('Canal non enregistré');

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'Canal non enregistré', 'PRIVMSG');

        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([$cmd]),
            $userLookup,
            $notifier,
            $preferences,
            $translator,
            $userAccountPort,
            'es',
        );

        $listener->onCommand(self::SENDER_UID, 'FAIL');
    }

    #[Test]
    public function handlesChannelNotRegisteredExceptionWithDefaultLanguageAndNoticeWhenNoAccount(): void
    {
        $cmd = $this->createThrowCommand('FAIL', ChannelNotRegisteredException::forChannel('#channel'));

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(self::senderView());

        $preferences = $this->createMock(ServiceUserPreferences::class);
        $preferences->expects(self::once())
            ->method('prefersPrivateMessages')
            ->with('TestUser')
            ->willReturn(false);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())
            ->method('findAccountByNick')
            ->with('TestUser')
            ->willReturn(null);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('error.channel_not_registered', ['%channel%' => '#channel', '%bot%' => self::BOT_NICK], 'memoserv', 'es')
            ->willReturn('Canal no registrado');

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'Canal no registrado', 'NOTICE');

        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([$cmd]),
            $userLookup,
            $notifier,
            $preferences,
            $translator,
            $userAccountPort,
            'es',
        );

        $listener->onCommand(self::SENDER_UID, 'FAIL');
    }

    #[Test]
    public function handlesInsufficientAccessException(): void
    {
        $cmd = $this->createThrowCommand('FAIL', InsufficientAccessException::forOperation('#channel', 'MEMOREAD'));

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(self::senderView());

        $preferences = $this->createMock(ServiceUserPreferences::class);
        $preferences->expects(self::once())
            ->method('prefersPrivateMessages')
            ->with('TestUser')
            ->willReturn(false);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())
            ->method('findAccountByNick')
            ->with('TestUser')
            ->willReturn(new MemoAccountView(1, 'TestUser', 'en'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('error.insufficient_access', [
                '%operation%' => 'MEMOREAD',
                '%channel%' => '#channel',
                '%bot%' => self::BOT_NICK,
            ], 'memoserv', 'en')
            ->willReturn('Access denied');

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with(self::SENDER_UID, 'Access denied', 'NOTICE');

        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([$cmd]),
            $userLookup,
            $notifier,
            $preferences,
            $translator,
            $userAccountPort,
            'en',
        );

        $listener->onCommand(self::SENDER_UID, 'FAIL');
    }

    #[Test]
    public function logsErrorOnGenericThrowable(): void
    {
        $exception = new RuntimeException('Database failure');
        $cmd = $this->createThrowCommand('FAIL', $exception);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByUid')
            ->with(self::SENDER_UID)
            ->willReturn(self::senderView());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('MemoServ dispatch error: Database failure', [
                'exception' => $exception,
                'sender' => self::SENDER_UID,
            ]);

        $listener = new MemoServCommandListener(
            $this->createBot(),
            $this->createService([$cmd]),
            $userLookup,
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(ServiceUserPreferences::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(MemoUserAccountPort::class),
            'en',
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'FAIL');
    }
}
