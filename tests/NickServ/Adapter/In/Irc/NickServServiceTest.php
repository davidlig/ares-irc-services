<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
use App\NickServ\Adapter\In\Irc\Command\SasetCommand;
use App\NickServ\Adapter\In\Irc\Command\SetCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\In\Irc\NickServService;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Adapter\Out\User\UserLanguageResolver;
use App\NickServ\Adapter\Out\User\UserMessageTypeResolver;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Set\SetNickSettingHandlerInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Application\Port\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

final class NickServTestContextHolder
{
    public ?NickServContext $context = null;
}

#[CoversClass(NickServService::class)]
final class NickServServiceTest extends TestCase
{
    #[Test]
    public function dispatchesToExistingCommandHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');

        $authorizationContext = $this->createStub(AuthorizationContextInterface::class);
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $pendingRegistry = new PendingVerificationRegistry();
        $recoveryRegistry = new RecoveryTokenRegistry();
        $logger = $this->createStub(LoggerInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $contextHolder = new NickServTestContextHolder();
        $handler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(
                private readonly NickServTestContextHolder $contextHolder,
            ) {}

            public function getName(): string
            {
                return 'FOO';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('dummy.syntax', [new Argument('argument')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'dummy.help';
            }

            public function getExampleKey(): string
            {
                return 'dummy.example';
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                $this->contextHolder->context = $context;

                return null;
            }
        };

        $registry = new NickServCommandRegistry([$handler]);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('es');
        $account->method('getTimezone')->willReturn('Europe/Madrid');
        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with($sender->nick)->willReturn($account);

        $service = new NickServService(
            $authorizationContext,
            $authorizationChecker,
            $registry,
            $nickRepository,
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            $messageTypeResolver,
            $translator,
            $pendingRegistry,
            $recoveryRegistry,
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('FOO arg1', $sender);

        self::assertInstanceOf(NickServContext::class, $contextHolder->context);
        self::assertSame('FOO', $contextHolder->context->command);
        self::assertSame(['arg1'], $contextHolder->context->args);
        self::assertSame($sender, $contextHolder->context->sender);
    }

    #[Test]
    public function repliesUnknownCommandWhenHandlerNotFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');

        $authorizationContext = $this->createStub(AuthorizationContextInterface::class);
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        foreach (['common', 'nickserv'] as $domain) {
            $translator->addResource('yaml', __DIR__ . '/../../../../../translations/' . $domain . '.en.yaml', 'en', $domain);
        }
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['presentation.unknown_command' => 'Unknown %command%. Use /msg %bot% %help%'], 'en', 'common');
        $expected = "\x0304✗\x03\x0F Unknown \x02\x0303UNKNOWN\x03\x0F. Use /msg NickServ \x02\x0303HELP\x03\x0F";
        $pendingRegistry = new PendingVerificationRegistry();
        $recoveryRegistry = new RecoveryTokenRegistry();
        $logger = $this->createStub(LoggerInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $registry = new NickServCommandRegistry([]);

        $notifier->method('getNick')->willReturn('NickServ');

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, $expected, 'NOTICE');

        $service = new NickServService(
            $authorizationContext,
            $authorizationChecker,
            $registry,
            $nickRepository,
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            $messageTypeResolver,
            $translator,
            $pendingRegistry,
            $recoveryRegistry,
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
            'en',
            'UTC',
            $logger,
            new IrcRenderer(
                new FixedStyleProvider(PresentationStyle::default()),
            ),
        );

        $service->dispatch('UNKNOWN arg', $sender);
    }

    #[Test]
    public function repliesUnknownCommandWithoutRendererUsingLegacyTranslation(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');

        $authorizationContext = $this->createStub(AuthorizationContextInterface::class);
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        foreach (['common', 'nickserv'] as $domain) {
            $translator->addResource('yaml', __DIR__ . '/../../../../../translations/' . $domain . '.en.yaml', 'en', $domain);
        }
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['presentation.unknown_command' => 'Unknown %command%. Use /msg %bot% %help%'], 'en', 'common');
        $expected = $translator->trans('unknown_command', ['%command%' => 'UNKNOWN', '%bot%' => 'NickServ'], 'common', 'en');
        $pendingRegistry = new PendingVerificationRegistry();
        $recoveryRegistry = new RecoveryTokenRegistry();
        $logger = $this->createStub(LoggerInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $registry = new NickServCommandRegistry([]);

        $notifier->method('getNick')->willReturn('NickServ');

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, $expected, 'NOTICE');

        $service = new NickServService(
            $authorizationContext,
            $authorizationChecker,
            $registry,
            $nickRepository,
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            $messageTypeResolver,
            $translator,
            $pendingRegistry,
            $recoveryRegistry,
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('UNKNOWN arg', $sender);
    }

    #[Test]
    public function emptyCommandDoesNothing(): void
    {
        $sender = new SenderView('UID1', 'N', 'i', 'h', 'c', '127.0.0.1');
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry([]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }

    #[Test]
    public function repliesPermissionDeniedWhenRequiredPermissionNotGranted(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new NickServTestContextHolder();

        $permissionHandler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(private readonly NickServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'NEEDPERM';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return NickServPermission::IDENTIFIED_OWNER;
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                $this->holder->context = $context;

                return null;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with(NickServPermission::IDENTIFIED_OWNER, self::anything())
            ->willReturn(false);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied' : $id
        );
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Permission denied', 'NOTICE');

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            new NickServCommandRegistry([$permissionHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
        );

        $service->dispatch('NEEDPERM', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndUserNotIdentified(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new NickServTestContextHolder();

        $identifiedHandler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(private readonly NickServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'NEEDID';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'IDENTIFIED';
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                $this->holder->context = $context;

                return null;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('IDENTIFIED', self::anything())
            ->willReturn(false);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.not_identified' === $id ? 'Not identified' : $id
        );
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Not identified', 'NOTICE');

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            new NickServCommandRegistry([$identifiedHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
        );

        $service->dispatch('NEEDID', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesSyntaxWhenArgsBelowMinArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new NickServTestContextHolder();

        $minArgsHandler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(private readonly NickServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'TWOARGS';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax.twoargs', [new Argument('first'), new Argument('second')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                $this->holder->context = $context;

                return null;
            }
        };

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => 'error.syntax' === $id ? 'Syntax: ' . (is_string($params['syntax'] ?? null) ? $params['syntax'] : '') : $id
        );
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, self::stringContains('Syntax:'), 'NOTICE');

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry([$minArgsHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
        );

        $service->dispatch('TWOARGS onlyone', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function routesSetAndSasetPasswordSyntaxFromTheRecognizedOption(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, true, '001', 'cloak');
        $messages = [];
        $handler = $this->createMock(SetNickSettingHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $repository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []): string {
            return match ($id) {
                'set.password.syntax' => 'SET PASSWORD <new_password>',
                'saset.password.syntax' => 'SASET <nickname> PASSWORD <new_password>',
                'error.syntax' => 'Syntax: ' . (is_string($parameters['%syntax%'] ?? null) ? $parameters['%syntax%'] : ''),
                default => $id,
            };
        });
        $dispatchedEvents = [];
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::exactly(2))->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): void {
            $dispatchedEvents[] = $event;
        });

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            new NickServCommandRegistry([new SetCommand($handler), new SasetCommand($handler)]),
            $repository,
            new UserLanguageResolver($repository, new SessionLanguageRegistry(), 'en'),
            $notifier,
            new UserMessageTypeResolver($repository),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $events,
        );

        $service->dispatch('SET PASSWORD', $sender);
        $service->dispatch('SASET Target PASSWORD', $sender);

        self::assertSame([
            'Syntax: SET PASSWORD <new_password>',
            'Syntax: SASET <nickname> PASSWORD <new_password>',
        ], $messages);
        self::assertCount(2, $dispatchedEvents);
        foreach ($dispatchedEvents as $event) {
            self::assertInstanceOf(CommandExecutedEvent::class, $event);
            self::assertFalse($event->outcome?->success);
        }
    }

    #[Test]
    public function clearsAuthorizationContextEvenWhenHandlerThrowsException(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');

        $exceptionHandler = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'CRASH';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                throw new RuntimeException('Handler error');
            }
        };

        $authorizationContext = $this->createMock(AuthorizationContextInterface::class);
        $authorizationContext->expects(self::once())->method('setCurrentUser')->with('UID1', true, false);
        $authorizationContext->expects(self::once())->method('clear');

        $service = new NickServService(
            $authorizationContext,
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry([$exceptionHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $this->createStub(NickServNotifierInterface::class),
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler error');
        $service->dispatch('CRASH', $sender);
    }

    #[Test]
    public function dispatchesCommandExecutedEventWithSuccessfulOutcome(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new NickServTestContextHolder();

        $auditableHandler = new class($contextHolder) implements NickServCommandInterface, IrcopAuditableCommandInterface {
            public function __construct(private readonly NickServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'AUDITCMD';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'NICKSERV_ADMIN';
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): CommandOutcome
            {
                $auditData = new IrcopAuditData(
                    target: 'TargetNick',
                    targetHost: 'user@host',
                    targetIp: '127.0.0.1',
                    reason: 'test reason',
                    extra: ['key' => 'value'],
                );
                $this->holder->context = $context;

                return CommandOutcome::success($auditData);
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('NICKSERV_ADMIN', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => $auditableHandler === $event->command
                && 'nickserv' === $event->serviceName
                && 'Nick' === $event->operatorNick
                && 'AUDITCMD' === $event->commandName
                && 'NICKSERV_ADMIN' === $event->permission
                && true === $event->outcome?->success
                && 'TargetNick' === $event->outcome->auditData?->target));

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('en');
        $account->method('getTimezone')->willReturn('UTC');
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);

        $registry = new NickServCommandRegistry([$auditableHandler]);

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('getServiceKey')->willReturn('nickserv');

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $registry,
            $nickRepository,
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $eventDispatcher,
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
        );

        $service->dispatch('AUDITCMD', $sender);

        self::assertInstanceOf(NickServContext::class, $contextHolder->context);
    }

    #[Test]
    public function dispatchesCommandExecutedEventForNonAuditableHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new NickServTestContextHolder();

        $nonAuditableHandler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(private readonly NickServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'NONAUDIT';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'NICKSERV_OP';
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                $this->holder->context = $context;

                return null;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('NICKSERV_OP', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => $nonAuditableHandler === $event->command && null === $event->outcome));

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('en');
        $account->method('getTimezone')->willReturn('UTC');
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);

        $registry = new NickServCommandRegistry([$nonAuditableHandler]);

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $registry,
            $nickRepository,
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $this->createStub(NickServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $eventDispatcher,
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
        );

        $service->dispatch('NONAUDIT', $sender);

        self::assertInstanceOf(NickServContext::class, $contextHolder->context);
    }

    #[Test]
    public function dispatchesCommandExecutedEventWithRejectedOutcome(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new NickServTestContextHolder();

        $auditableHandler = new class($contextHolder) implements NickServCommandInterface, IrcopAuditableCommandInterface {
            public function __construct(private readonly NickServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'FAILCMD';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return 'NICKSERV_ADMIN';
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): CommandOutcome
            {
                $this->holder->context = $context;

                return CommandOutcome::rejected();
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('NICKSERV_ADMIN', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => false === $event->outcome?->success));

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('en');
        $account->method('getTimezone')->willReturn('UTC');
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);

        $registry = new NickServCommandRegistry([$auditableHandler]);

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $registry,
            $nickRepository,
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $this->createStub(NickServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $eventDispatcher,
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
        );

        $service->dispatch('FAILCMD', $sender);

        self::assertInstanceOf(NickServContext::class, $contextHolder->context);
    }

    #[Test]
    public function blocksNormalCommandsWhenAccountIsPendingDeletion(): void
    {
        $sender = new SenderView('UID1', 'DroppedNick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new NickServTestContextHolder();

        $handler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(private readonly NickServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): string
            {
                return NickServPermission::IDENTIFIED_OWNER;
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): null
            {
                $this->holder->context = $context;

                return null;
            }
        };

        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPendingDeletion')->willReturn(true);
        $account->method('getLanguage')->willReturn('en');
        $account->method('getTimezone')->willReturn('UTC');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);

        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->expects(self::once())->method('sendMessage')
            ->with($sender->uid, self::stringContains('drop.pending_deletion'));

        $registry = new NickServCommandRegistry([$handler]);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('drop.pending_deletion-translated');

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $registry,
            $nickRepository,
            new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventBusInterface::class),
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
        );

        $service->dispatch('SET PRIVATE', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function allowsRegisterInfoRestoreAndDropWhenAccountIsPendingDeletion(): void
    {
        foreach (['REGISTER', 'INFO', 'RESTORE', 'DROP'] as $allowedCommand) {
            $sender = new SenderView('UID1', 'DroppedNick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
            $contextHolder = new NickServTestContextHolder();

            $handler = new class($contextHolder, $allowedCommand) implements NickServCommandInterface {
                public function __construct(
                    private readonly NickServTestContextHolder $holder,
                    private readonly string $name,
                ) {}

                public function getName(): string
                {
                    return $this->name;
                }

                public function getAliases(): array
                {
                    return [];
                }

                public function getArgumentDefinition(): CommandArgumentDefinition
                {
                    return new CommandArgumentDefinition('syntax');
                }

                public function getMinArgs(): int
                {
                    return $this->getArgumentDefinition()->minimumArgs();
                }

                public function getSyntaxKey(): string
                {
                    return $this->getArgumentDefinition()->syntaxKey;
                }

                public function getHelpKey(): string
                {
                    return 'help';
                }

                public function getExampleKey(): string
                {
                    return 'help.example';
                }

                public function getOrder(): int
                {
                    return 0;
                }

                public function getShortDescKey(): string
                {
                    return 'short';
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

                public function getHelpParams(): array
                {
                    return [];
                }

                public function execute(NickServContext $context): null
                {
                    $this->holder->context = $context;

                    return null;
                }
            };

            $account = $this->createStub(RegisteredNick::class);
            $account->method('isPendingDeletion')->willReturn(true);
            $account->method('getLanguage')->willReturn('en');
            $account->method('getTimezone')->willReturn('UTC');

            $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
            $nickRepository->method('findByNick')->willReturn($account);

            $registry = new NickServCommandRegistry([$handler]);
            $service = new NickServService(
                $this->createStub(AuthorizationContextInterface::class),
                $this->createStub(AuthorizationCheckerInterface::class),
                $registry,
                $nickRepository,
                new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en'),
                $this->createStub(NickServNotifierInterface::class),
                new UserMessageTypeResolver($nickRepository),
                $this->createStub(TranslatorInterface::class),
                new PendingVerificationRegistry(),
                new RecoveryTokenRegistry(),
                $this->createServiceNicks(),
                $this->createStub(EventBusInterface::class),
                'en',
                'UTC',
                $this->createStub(LoggerInterface::class),
            );

            $service->dispatch("{$allowedCommand} arg", $sender);

            self::assertNotNull($contextHolder->context, "Command {$allowedCommand} should be allowed for pending deletion account");
        }
    }

    private function createServiceNicks(): ServiceNicknameRegistry
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
}
