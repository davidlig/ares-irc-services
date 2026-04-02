<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\MemoServ\MemoServService;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\Port\SenderView;
use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use App\Domain\MemoServ\Exception\MemoDisabledException;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserMessageTypeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServService::class)]
final class MemoServServiceTest extends TestCase
{
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

    #[Test]
    public function dispatchesToExistingCommandHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $contextHolder = new stdClass();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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
        $service = $this->createMemoServService(
            $registry,
            $nickRepository,
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $translator,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('FOO arg1', $sender);

        self::assertInstanceOf(MemoServContext::class, $contextHolder->context);
        self::assertSame('FOO', $contextHolder->context->command);
        self::assertSame(['arg1'], $contextHolder->context->args);
        self::assertSame($sender, $contextHolder->context->sender);
    }

    #[Test]
    public function repliesUnknownCommandWhenHandlerNotFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $notifier->method('getNick')->willReturn('MemoServ');
        $translator->expects(self::once())->method('trans')
            ->with('unknown_command', ['%command%' => 'UNKNOWN', '%bot%' => 'MemoServ'], 'memoserv', 'en')
            ->willReturn('Unknown command');
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Unknown command', 'NOTICE');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([]),
            $nickRepository,
            $notifier,
            new UserMessageTypeResolver($nickRepository),
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
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            $this->createServiceNicks(),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }

    #[Test]
    public function repliesPermissionDeniedWhenHandlerRequiresPermissionAndUserLacksIt(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $permissionHandler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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

            public function getRequiredPermission(): ?string
            {
                return 'MEMOSERV_OP_TEST';
            }

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('MEMOSERV_OP_TEST', self::anything())
            ->willReturn(false);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied' : $id
        );
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Permission denied', 'NOTICE');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$permissionHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('OPCMD', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndNoAccount(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $identifiedHandler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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

            public function getRequiredPermission(): ?string
            {
                return 'IDENTIFIED';
            }

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.not_identified' === $id ? 'Not identified' : $id
        );
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, 'Not identified', 'NOTICE');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$identifiedHandler]),
            $nickRepository,
            $notifier,
            new UserMessageTypeResolver($nickRepository),
            $translator,
            $this->createServiceNicks(),
        );

        $service->dispatch('NEEDID', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesSyntaxWhenArgsBelowMinArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $minArgsHandler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

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

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => 'error.syntax' === $id ? 'Syntax: ' . ($params['syntax'] ?? '') : $id
        );
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, self::stringContains('Syntax:'), 'NOTICE');

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$minArgsHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            $this->createServiceNicks(),
        );

        $service->dispatch('TWOARGS onlyone', $sender);

        self::assertNull($contextHolder->context);
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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$throwMemoDisabled]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            $this->createServiceNicks(),
        );

        $service->dispatch('DISABLED', $sender);
    }

    #[Test]
    public function usesAccountLanguageAndTimezoneWhenAccountFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');

        $account = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $account->method('getLanguage')->willReturn('es');
        $account->method('getTimezone')->willReturn('Europe/Madrid');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);

        $contextHolder = new stdClass();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'TEST';
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

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $registry = new MemoServCommandRegistry([$handler]);
        $service = $this->createMemoServService(
            $registry,
            $nickRepository,
            $this->createStub(MemoServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
        );

        $service->dispatch('TEST', $sender);

        self::assertInstanceOf(MemoServContext::class, $contextHolder->context);
        self::assertSame('es', $contextHolder->context->getLanguage());
        self::assertSame('Europe/Madrid', $contextHolder->context->getTimezone());
    }

    #[Test]
    public function usesDefaultLanguageAndTimezoneWhenAccountNotFound(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip');

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $contextHolder = new stdClass();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'TEST';
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

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $registry = new MemoServCommandRegistry([$handler]);
        $service = $this->createMemoServService(
            $registry,
            $nickRepository,
            $this->createStub(MemoServNotifierInterface::class),
            new UserMessageTypeResolver($nickRepository),
            $this->createStub(TranslatorInterface::class),
            $this->createServiceNicks(),
            'fr',
            'Europe/Paris',
        );

        $service->dispatch('TEST', $sender);

        self::assertInstanceOf(MemoServContext::class, $contextHolder->context);
        self::assertSame('fr', $contextHolder->context->getLanguage());
        self::assertSame('Europe/Paris', $contextHolder->context->getTimezone());
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
                throw new RuntimeException('Handler failed for test');
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(
                'MemoServ dispatch error: Handler failed for test',
                self::callback(static fn (array $context): bool => isset($context['exception']) && $context['exception'] instanceof RuntimeException
                    && isset($context['sender']) && 'UID1' === $context['sender'])
            );

        $service = $this->createMemoServService(
            new MemoServCommandRegistry([$throwingHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MemoServNotifierInterface::class),
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler failed for test');

        $service->dispatch('THROW', $sender);
    }

    #[Test]
    public function dispatchesIrcopCommandExecutedEventWhenHandlerIsAuditableAndHasPermission(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $auditableHandler = new class($contextHolder) implements MemoServCommandInterface, AuditableCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            private ?IrcopAuditData $auditData = null;

            public function getName(): string
            {
                return 'AUDITCMD';
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
                return 'MEMOSERV_ADMIN';
            }

            public function execute(MemoServContext $context): void
            {
                $this->auditData = new IrcopAuditData(
                    target: 'TargetNick',
                    reason: 'test reason',
                );
                $this->holder->context = $context;
            }

            public function getAuditData(object $context): ?IrcopAuditData
            {
                return $this->auditData;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('MEMOSERV_ADMIN', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (IrcopCommandExecutedEvent $event): bool => 'Nick' === $event->operatorNick
                && 'AUDITCMD' === $event->commandName
                && 'MEMOSERV_ADMIN' === $event->permission
                && 'TargetNick' === $event->target
                && 'test reason' === $event->reason));

        $registry = new MemoServCommandRegistry([$auditableHandler]);

        $service = $this->createMemoServService(
            $registry,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MemoServNotifierInterface::class),
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('AUDITCMD', $sender);

        self::assertInstanceOf(MemoServContext::class, $contextHolder->context);
    }

    #[Test]
    public function dispatchesIrcopCommandExecutedEventWithNullAuditDataWhenNotAuditable(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        // Handler implements MemoServCommandInterface but NOT AuditableCommandInterface
        $nonAuditableHandler = new class($contextHolder) implements MemoServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'NONAUDIT';
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
                return 'MEMOSERV_ADMIN';
            }

            public function execute(MemoServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('MEMOSERV_ADMIN', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (IrcopCommandExecutedEvent $event): bool => 'Nick' === $event->operatorNick
                && 'NONAUDIT' === $event->commandName
                && 'MEMOSERV_ADMIN' === $event->permission
                && null === $event->target
                && null === $event->targetHost
                && null === $event->targetIp
                && null === $event->reason
                && [] === $event->extra));

        $registry = new MemoServCommandRegistry([$nonAuditableHandler]);

        $service = $this->createMemoServService(
            $registry,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MemoServNotifierInterface::class),
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            $this->createServiceNicks(),
            'en',
            'UTC',
            null,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('NONAUDIT', $sender);

        self::assertInstanceOf(MemoServContext::class, $contextHolder->context);
    }

    /**
     * Creates a MemoServService with the required authorization dependencies.
     */
    private function createMemoServService(
        MemoServCommandRegistry $registry,
        RegisteredNickRepositoryInterface $nickRepository,
        MemoServNotifierInterface $notifier,
        UserMessageTypeResolver $messageTypeResolver,
        TranslatorInterface $translator,
        ServiceNicknameRegistry $serviceNicks,
        string $defaultLanguage = 'en',
        string $defaultTimezone = 'UTC',
        ?LoggerInterface $logger = null,
        ?AuthorizationContextInterface $authorizationContext = null,
        ?AuthorizationCheckerInterface $authorizationChecker = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): MemoServService {
        return new MemoServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $serviceNicks,
            $authorizationContext ?? $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker ?? $this->createStub(AuthorizationCheckerInterface::class),
            $eventDispatcher ?? $this->createStub(EventDispatcherInterface::class),
            $defaultLanguage,
            $defaultTimezone,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
