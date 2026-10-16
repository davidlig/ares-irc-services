<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Subscriber;

use App\CRegServ\Adapter\In\Irc\Bot\CRegServBot;
use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationCheckerInterface;
use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationContextInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Adapter\In\Irc\CRegServService;
use App\CRegServ\Adapter\In\Irc\CRegServUserPresentationPreferences;
use App\CRegServ\Adapter\In\Irc\Subscriber\CRegServCommandListener;
use App\CRegServ\Application\Port\Out\CRegServUserAccountPort;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Shared\Application\Port\EventBusInterface;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

#[CoversClass(CRegServCommandListener::class)]
final class CRegServCommandListenerTest extends TestCase
{
    private const string CREGSERV_UID = '001DC';

    private const string SENDER_UID = '001ABC';

    private MockObject&NetworkUserLookupPort $userLookup;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createBot(): CRegServBot
    {
        $uidGenerator = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGenerator->method('generateUid')->willReturn(self::CREGSERV_UID);

        $bot = new CRegServBot(
            new ActiveConnectionHolder(),
            $this->createStub(SendNoticePort::class),
            $uidGenerator,
            'services.example.com',
            logger: $this->createStub(LoggerInterface::class),
        );
        $bot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        return $bot;
    }

    /**
     * @param iterable<CRegServCommandInterface> $commands
     */
    private function createService(iterable $commands = []): CRegServService
    {
        $messageTypeResolver = $this->createStub(CRegServUserPresentationPreferences::class);
        $messageTypeResolver->method('prefersPrivateMessages')->willReturn(false);
        $languageResolver = $this->createStub(CRegServUserPresentationPreferences::class);
        $languageResolver->method('languageFor')->willReturn('en');

        $authorizationChecker = $this->createStub(CRegServAuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        $nickservProvider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
        $cregservProvider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'cregserv';
            }

            public function getNickname(): string
            {
                return 'CReG';
            }
        };

        return new CRegServService(
            commandRegistry: new CRegServCommandRegistry($commands),
            userAccountPort: $this->createStub(CRegServUserAccountPort::class),
            languageResolver: $languageResolver,
            notifier: $this->createStub(CRegServNotifierInterface::class),
            messageTypeResolver: $messageTypeResolver,
            translator: $this->createStub(TranslatorInterface::class),
            serviceNicks: new ServiceNicknameRegistry([$nickservProvider, $cregservProvider]),
            authorizationContext: $this->createStub(CRegServAuthorizationContextInterface::class),
            authorizationChecker: $authorizationChecker,
            eventDispatcher: $this->createStub(EventBusInterface::class),
        );
    }

    private function createListener(CRegServService $service): CRegServCommandListener
    {
        return new CRegServCommandListener(
            $this->createBot(),
            $service,
            $this->userLookup,
            $this->logger,
        );
    }

    private function createSenderView(): SenderView
    {
        return new SenderView(self::SENDER_UID, 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true);
    }

    /**
     * A command that records the context it received and, optionally, fails.
     *
     * @param Closure(CRegServContext): void|null $onExecute
     */
    private function createCommand(string $name, ?Closure $onExecute = null, ?Throwable $failure = null): CRegServCommandInterface
    {
        return new class($name, $onExecute, $failure) implements CRegServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly ?Closure $onExecute,
                private readonly ?Throwable $failure,
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
                return 'stub.syntax';
            }

            public function getHelpKey(): string
            {
                return 'stub.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'stub.short';
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

            public function execute(CRegServContext $context): ?CommandOutcome
            {
                if (null !== $this->failure) {
                    throw $this->failure;
                }
                if (null !== $this->onExecute) {
                    ($this->onExecute)($context);
                }

                return null;
            }
        };
    }

    #[Test]
    public function getServiceNameReturnsBotNickname(): void
    {
        $listener = $this->createListener($this->createService());
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame('CReG', $listener->getServiceName());
    }

    #[Test]
    public function getServiceUidReturnsBotUidAfterBurst(): void
    {
        $listener = $this->createListener($this->createService());
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->logger->expects(self::never())->method('warning');

        self::assertSame(self::CREGSERV_UID, $listener->getServiceUid());
    }

    #[Test]
    public function onCommandWithEmptyTextDoesNothing(): void
    {
        $listener = $this->createListener($this->createService());
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->logger->expects(self::never())->method('warning');

        $listener->onCommand(self::SENDER_UID, '');
    }

    #[Test]
    public function onCommandWhenSenderIsUnknownLogsWarningAndStops(): void
    {
        $listener = $this->createListener($this->createService());
        $this->userLookup->expects(self::once())->method('findByUid')->with('999XXX')->willReturn(null);
        $this->logger->expects(self::once())->method('warning')
            ->with('CRegServ: could not resolve sender UID: 999XXX');

        $listener->onCommand('999XXX', 'ESTADO #canal');
    }

    #[Test]
    public function onCommandDispatchesToServiceWithResolvedSender(): void
    {
        $sender = $this->createSenderView();
        $this->userLookup->expects(self::once())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $executed = false;
        $command = $this->createCommand('ESTADO', static function (CRegServContext $context) use (&$executed, $sender): void {
            $executed = true;
            self::assertSame($sender->uid, $context->sender?->uid);
            self::assertSame('ESTADO', $context->command);
        });
        $listener = $this->createListener($this->createService([$command]));
        $this->logger->expects(self::never())->method('error');

        $listener->onCommand(self::SENDER_UID, 'ESTADO #canal');

        self::assertTrue($executed);
    }

    #[Test]
    public function onCommandLogsAndSwallowsDispatchFailures(): void
    {
        $sender = $this->createSenderView();
        $this->userLookup->expects(self::once())->method('findByUid')->with(self::SENDER_UID)->willReturn($sender);

        $errors = [];
        $this->logger->expects(self::once())->method('error')->willReturnCallback(
            static function (string $message, array $context = []) use (&$errors): void {
                $errors[] = [$message, $context];
            },
        );

        $command = $this->createCommand('ESTADO', failure: new RuntimeException('boom'));
        $listener = $this->createListener($this->createService([$command]));

        $listener->onCommand(self::SENDER_UID, 'ESTADO #canal');

        self::assertCount(1, $errors);
        self::assertSame('CRegServ dispatch error: boom', $errors[0][0]);
        self::assertInstanceOf(RuntimeException::class, $errors[0][1]['exception']);
        self::assertSame(self::SENDER_UID, $errors[0][1]['sender']);
    }
}
