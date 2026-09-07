<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc;

use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationCheckerInterface;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationContextInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Adapter\In\Irc\MemoServService;
use App\MemoServ\Adapter\In\Irc\MemoServUserPresentationPreferences;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Domain\Exception\MemoDisabledException;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function is_string;

final class MemoServContextHolder
{
    public ?MemoServContext $context = null;

    public function getContext(): ?MemoServContext
    {
        return $this->context;
    }
}

#[CoversClass(MemoServService::class)]
final class MemoServServiceTest extends TestCase
{
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

    #[Test]
    public function dispatchesToExistingCommandHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $contextHolder = new MemoServContextHolder();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly MemoServContextHolder $holder) {}

            public function getName(): string
            {
                return 'FOO';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
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

        $registry = new MemoServCommandRegistry([$handler]);
        $authorizationContext = $this->createMock(MemoAuthorizationContextInterface::class);
        $authorizationContext->expects(self::once())
            ->method('setCurrentUser')
            ->with('UID1', true, false);
        $authorizationContext->expects(self::once())->method('clear');
        $service = $this->createMemoServService(
            $registry,
            $userAccountPort,
            $notifier,
            $this->createMessageTypeResolver(),
            $translator,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
            $authorizationContext,
        );

        $service->dispatch('FOO arg1', $sender);

        $context = $contextHolder->getContext();
        self::assertInstanceOf(MemoServContext::class, $context);
        self::assertSame('FOO', $context->command);
        self::assertSame(['arg1'], $context->args);
        self::assertSame($sender, $context->sender);
    }

    #[Test]
    public function repliesUnknownCommandWhenHandlerNotFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $translator = $this->createMock(TranslationInterface::class);
        $notifier->method('getNick')->willReturn('MemoServ');
        $translator->expects(self::once())->method('trans')
            ->with('unknown_command', ['%command%' => 'UNKNOWN', '%bot%' => 'MemoServ'], 'memoserv', 'en')
            ->willReturn('Unknown command');
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Unknown command', 'NOTICE');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([]),
            $userAccountPort,
            $notifier,
            $this->createMessageTypeResolver(),
            $translator,
            $this->createServiceNicks(),
        );

        $service->dispatch('UNKNOWN arg', $sender);
    }

    #[Test]
    public function emptyCommandDoesNothing(): void
    {
        $sender = new SenderView('UID1', 'N', 'i', 'h', 'c', 'ip');
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([]),
            $this->createStub(MemoUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver(),
            $this->createStub(TranslationInterface::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }

    #[Test]
    public function repliesPermissionDeniedWhenHandlerRequiresPermissionAndUserLacksIt(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new MemoServContextHolder();
        $contextHolder->context = null;

        $permissionHandler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly MemoServContextHolder $holder) {}

            public function getName(): string
            {
                return 'OPCMD';
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
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
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
                return 'MEMOSERV_OP_TEST';
            }

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $authorizationChecker = $this->createMock(MemoAuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('MEMOSERV_OP_TEST', self::anything())
            ->willReturn(false);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied' : $id
        );
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Permission denied', 'NOTICE');
        $authorizationContext = $this->createMock(MemoAuthorizationContextInterface::class);
        $authorizationContext->expects(self::once())
            ->method('setCurrentUser')
            ->with('UID1', true, false);
        $authorizationContext->expects(self::once())->method('clear');
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$permissionHandler]),
            $this->createStub(MemoUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver(),
            $translator,
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $authorizationContext,
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('OPCMD', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndUserNotGranted(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', false, false, '001', 'cloak');
        $contextHolder = new MemoServContextHolder();
        $contextHolder->context = null;

        $identifiedHandler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly MemoServContextHolder $holder) {}

            public function getName(): string
            {
                return 'NEEDID';
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
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
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

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $authorizationChecker = $this->createMock(MemoAuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('IDENTIFIED', self::anything())
            ->willReturn(false);

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.not_identified' === $id ? 'Not identified' : $id
        );
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Not identified', 'NOTICE');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$identifiedHandler]),
            $this->createStub(MemoUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver(),
            $translator,
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(MemoAuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('NEEDID', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function repliesSyntaxWhenArgsBelowMinArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new MemoServContextHolder();
        $contextHolder->context = null;

        $minArgsHandler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly MemoServContextHolder $holder) {}

            public function getName(): string
            {
                return 'TWOARGS';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 2;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax.twoargs';
            }

            public function getHelpKey(): string
            {
                return 'help';
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

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static function (string $id, array $params = []): string {
                $syntax = $params['syntax'] ?? null;

                return 'error.syntax' === $id ? 'Syntax: ' . (is_string($syntax) ? $syntax : '') : $id;
            }
        );
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, self::stringContains('Syntax:'), 'NOTICE');
        $authorizationContext = $this->createMock(MemoAuthorizationContextInterface::class);
        $authorizationContext->expects(self::once())
            ->method('setCurrentUser')
            ->with('UID1', true, false);
        $authorizationContext->expects(self::once())->method('clear');
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$minArgsHandler]),
            $this->createStub(MemoUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver(),
            $translator,
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $authorizationContext,
            null,
            $eventDispatcher,
        );

        $service->dispatch('TWOARGS onlyone', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function whenHandlerThrowsMemoDisabledExceptionRepliesServiceDisabled(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $throwMemoDisabled = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'DISABLED';
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
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
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

            public function execute(MemoServContext $context): void
            {
                throw MemoDisabledException::forTarget('TargetNick');
            }
        };

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')
            ->with($sender->uid, self::stringContains('service_disabled'), 'NOTICE');

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$throwMemoDisabled]),
            $this->createStub(MemoUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver(),
            $translator,
            $this->createServiceNicks(),
        );

        $service->dispatch('DISABLED', $sender);
    }

    #[Test]
    public function whenHandlerThrowsGenericThrowableLogsAndRethrows(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $throwingHandler = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'THROW';
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
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
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

            public function execute(MemoServContext $context): void
            {
                throw new RuntimeException('Unexpected crash');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('MemoServ dispatch error'));
        $authorizationContext = $this->createMock(MemoAuthorizationContextInterface::class);
        $authorizationContext->expects(self::once())
            ->method('setCurrentUser')
            ->with('UID1', true, false);
        $authorizationContext->expects(self::once())->method('clear');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$throwingHandler]),
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoServNotifierInterface::class),
            $this->createMessageTypeResolver(),
            $this->createStub(TranslationInterface::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
            $authorizationContext,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected crash');

        $service->dispatch('THROW', $sender);
    }

    #[Test]
    public function dispatchesCommandExecutedEvent(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $command = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'TESTCMD';
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
                return 'test.syntax';
            }

            public function getHelpKey(): string
            {
                return 'test.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'test.short';
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

            public function execute(MemoServContext $context): CommandOutcome
            {
                return CommandOutcome::success();
            }
        };

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $event): bool => $event instanceof CommandExecutedEvent
                && $event->command === $command
                && 'memoserv' === $event->serviceName
                && 'Nick' === $event->operatorNick
                && 'TESTCMD' === $event->commandName
                && null === $event->permission
                && true === $event->outcome?->success
                && null === $event->outcome->auditData));

        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('getServiceKey')->willReturn('memoserv');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$command]),
            $this->createStub(MemoUserAccountPort::class),
            $notifier,
            $this->createMessageTypeResolver(),
            $this->createStub(TranslationInterface::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            null,
            null,
            $eventDispatcher,
        );

        $service->dispatch('TESTCMD', $sender);
    }

    private function createMemoServService(
        MemoServCommandRegistry $registry,
        MemoUserAccountPort $userAccountPort,
        MemoServNotifierInterface $notifier,
        MemoServUserPresentationPreferences $messageTypeResolver,
        TranslationInterface $translator,
        ServiceNicknameRegistry $serviceNicks,
        string $defaultLanguage = 'en',
        string $defaultTimezone = 'UTC',
        ?LoggerInterface $logger = null,
        ?MemoAuthorizationContextInterface $authContext = null,
        ?MemoAuthorizationCheckerInterface $authChecker = null,
        ?EventBusInterface $eventDispatcher = null,
    ): MemoServService {
        $languageResolver = $this->createStub(MemoServUserPresentationPreferences::class);
        $languageResolver->method('languageFor')->willReturn('en');

        if (null === $authChecker) {
            $defaultAuthChecker = $this->createStub(MemoAuthorizationCheckerInterface::class);
            $defaultAuthChecker->method('isGranted')->willReturn(true);
            $authChecker = $defaultAuthChecker;
        }

        return new MemoServService(
            $registry,
            $userAccountPort,
            $languageResolver,
            $notifier,
            $messageTypeResolver,
            $translator,
            $serviceNicks,
            $authContext ?? $this->createStub(MemoAuthorizationContextInterface::class),
            $authChecker,
            $eventDispatcher ?? $this->createStub(EventBusInterface::class),
            $defaultLanguage,
            $defaultTimezone,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function createMessageTypeResolver(): MemoServUserPresentationPreferences
    {
        $resolver = $this->createStub(MemoServUserPresentationPreferences::class);
        $resolver->method('prefersPrivateMessages')->willReturn(false);

        return $resolver;
    }
}
