<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Subscriber;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
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
use App\MemoServ\Adapter\In\Irc\MemoServUserPresentationPreferences;
use App\MemoServ\Adapter\In\Irc\Subscriber\MemoServCommandListener;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\Shared\Application\Port\EventBusInterface;
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
        ?MemoServUserPresentationPreferences $preferences = null,
        ?MemoServNotifierInterface $notifier = null,
    ): MemoServService {
        return new MemoServService(
            commandRegistry: new MemoServCommandRegistry($commands),
            userAccountPort: $userAccountPort ?? $this->createStub(MemoUserAccountPort::class),
            languageResolver: $preferences ?? $this->createStub(MemoServUserPresentationPreferences::class),
            notifier: $notifier ?? $this->createStub(MemoServNotifierInterface::class),
            messageTypeResolver: $preferences ?? $this->createStub(MemoServUserPresentationPreferences::class),
            translator: $this->createStub(TranslatorInterface::class),
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

            public function execute(MemoServContext $context): null
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

            public function execute(MemoServContext $context): null
            {
                $this->state->executed = true;

                return null;
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
        );

        $listener->onCommand(self::SENDER_UID, 'SUCCESS');
        self::assertTrue($state->executed);
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
            $logger,
        );

        $listener->onCommand(self::SENDER_UID, 'FAIL');
    }
}
