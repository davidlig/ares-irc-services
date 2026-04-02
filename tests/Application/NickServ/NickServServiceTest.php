<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\NickServService;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
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

#[CoversClass(NickServService::class)]
final class NickServServiceTest extends TestCase
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

        $contextHolder = new stdClass();
        $contextHolder->context = null;
        $handler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(
                private readonly stdClass $contextHolder,
            ) {
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

            public function execute(NickServContext $context): void
            {
                $this->contextHolder->context = $context;
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
            $notifier,
            $messageTypeResolver,
            $translator,
            $pendingRegistry,
            $recoveryRegistry,
            $this->createServiceNicks(),
            $this->createStub(EventDispatcherInterface::class),
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
        $translator = $this->createMock(TranslatorInterface::class);
        $pendingRegistry = new PendingVerificationRegistry();
        $recoveryRegistry = new RecoveryTokenRegistry();
        $logger = $this->createStub(LoggerInterface::class);
        $messageTypeResolver = new UserMessageTypeResolver($nickRepository);

        $registry = new NickServCommandRegistry([]);

        $notifier->method('getNick')->willReturn('NickServ');
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'unknown_command',
                ['%command%' => 'UNKNOWN', '%bot%' => 'NickServ'],
                'nickserv',
                'en',
            )
            ->willReturn('Unknown command');

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, 'Unknown command', 'NOTICE');

        $service = new NickServService(
            $authorizationContext,
            $authorizationChecker,
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $pendingRegistry,
            $recoveryRegistry,
            $this->createServiceNicks(),
            $this->createStub(EventDispatcherInterface::class),
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
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventDispatcherInterface::class),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }

    #[Test]
    public function repliesPermissionDeniedWhenRequiredPermissionNotGranted(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $permissionHandler = new class($contextHolder) implements NickServCommandInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'NEEDPERM';
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
                return NickServPermission::IDENTIFIED_OWNER;
            }

            public function execute(NickServContext $context): void
            {
                $this->holder->context = $context;
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
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventDispatcherInterface::class),
        );

        $service->dispatch('NEEDPERM', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndUserNotIdentified(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $identifiedHandler = new class($contextHolder) implements NickServCommandInterface {
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

            public function execute(NickServContext $context): void
            {
                $this->holder->context = $context;
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
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventDispatcherInterface::class),
        );

        $service->dispatch('NEEDID', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function repliesSyntaxWhenArgsBelowMinArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $minArgsHandler = new class($contextHolder) implements NickServCommandInterface {
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

            public function execute(NickServContext $context): void
            {
                $this->holder->context = $context;
            }
        };

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => 'error.syntax' === $id ? 'Syntax: ' . ($params['syntax'] ?? '') : $id
        );
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with($sender->uid, self::stringContains('Syntax:'), 'NOTICE');

        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry([$minArgsHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventDispatcherInterface::class),
        );

        $service->dispatch('TWOARGS onlyone', $sender);

        self::assertNull($contextHolder->context);
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

            public function execute(NickServContext $context): void
            {
                throw new RuntimeException('Handler error');
            }
        };

        $authorizationContext = $this->createMock(AuthorizationContextInterface::class);
        $authorizationContext->expects(self::once())->method('setCurrentUser')->with($sender);
        $authorizationContext->expects(self::once())->method('clear');

        $service = new NickServService(
            $authorizationContext,
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry([$exceptionHandler]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickServNotifierInterface::class),
            new UserMessageTypeResolver($this->createStub(RegisteredNickRepositoryInterface::class)),
            $this->createStub(TranslatorInterface::class),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
            $this->createStub(EventDispatcherInterface::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler error');
        $service->dispatch('CRASH', $sender);
    }
}
