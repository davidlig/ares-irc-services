<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\Event\CommandExecutedEvent;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\OperServService;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Adapter\Out\User\UserLanguageResolver;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function is_string;

interface OperServCallbackCommandInterface extends OperServCommandInterface
{
    public function setExecuteCallback(Closure $callback): void;
}

final class OperServTestContextHolder
{
    public ?OperServContext $context = null;

    public function getContext(): ?OperServContext
    {
        return $this->context;
    }
}

#[CoversClass(OperServService::class)]
final class OperServServiceTest extends TestCase
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

    private function createAccessHelper(bool $isRoot = false, ?OperIrcop $ircop = null): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createAccessHelperForPermission(bool $hasPermission = false, int $nickId = 10): IrcopAccessHelper
    {
        $rootRegistry = new RootUserRegistry('');
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('hasPermission')->willReturn($hasPermission);

        if ($hasPermission) {
            $role = $this->createStub(OperRole::class);
            $role->method('getId')->willReturn(1);

            $ircop = $this->createStub(OperIrcop::class);
            $ircop->method('getRole')->willReturn($role);

            $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
            $ircopRepo->method('findByNickId')->willReturn($ircop);
        } else {
            $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
            $ircopRepo->method('findByNickId')->willReturn(null);
        }

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createAccessHelperForRoot(string $rootNick): IrcopAccessHelper
    {
        $rootRegistry = new RootUserRegistry($rootNick);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    #[Test]
    public function emptyTextDoesNothing(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1');
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $service = $this->createOperServService(
            new OperServCommandRegistry([]),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $notifier,
            $this->createStub(UserMessageTypeResolverInterface::class),
            $this->createStub(TranslationInterface::class),
            $this->createAccessHelper(),
            $this->createServiceNicks(),
        );

        $service->dispatch('   ', $sender);
        $service->dispatch('', $sender);
    }

    #[Test]
    public function unknownCommandSendsErrorMessage(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1');

        $registry = new OperServCommandRegistry([]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $messageTypeResolver = $this->createMock(UserMessageTypeResolverInterface::class);
        $translator = $this->createMock(TranslationInterface::class);

        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver->expects(self::once())
            ->method('resolve')
            ->with($sender)
            ->willReturn('NOTICE');
        $translator->expects(self::once())
            ->method('trans')
            ->with('unknown_command', ['%command%' => 'UNKNOWN', '%bot%' => 'OperServ'], 'operserv', 'en')
            ->willReturn('Unknown command UNKNOWN');

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, 'Unknown command UNKNOWN', 'NOTICE');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $this->createAccessHelper(),
            $this->createServiceNicks(),
        );

        $service->dispatch('UNKNOWN arg', $sender);
    }

    #[Test]
    public function permissionCheckRejectsNonOperWithoutRequiredPermission(): void
    {
        $sender = new SenderView('UID1', 'RegisteredNonIrcop', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', false, 'OPERSERV_OPCMD', 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'error.permission_denied' => 'Permission denied.',
                default => $id,
            }
        );

        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $logger = $this->createStub(LoggerInterface::class);

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('OPERSERV_OPCMD', self::anything())
            ->willReturn(false);

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, 'Permission denied.', 'NOTICE');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('OPCMD', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function operOnlyCommandAllowsOperViaIsOperFlag(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'ident', 'host', 'cloak', '127.0.0.1', false, true, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', true, null, 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $accessHelper = $this->createAccessHelper();
        $logger = $this->createStub(LoggerInterface::class);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('OPCMD', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    #[Test]
    public function operOnlyCommandAllowsOperViaAccessHelperIsRoot(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', true, null, 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $accessHelper = $this->createAccessHelperForRoot('TestUser');
        $logger = $this->createStub(LoggerInterface::class);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('OPCMD', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    #[Test]
    public function operOnlyCommandAllowsOperViaIrcopLookup(): void
    {
        $sender = new SenderView('UID1', 'IrcopNick', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', true, null, 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);

        $ircop = $this->createStub(OperIrcop::class);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())
            ->method('findByNick')
            ->with($sender->nick)
            ->willReturn($account);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $accessHelper = $this->createAccessHelper(isRoot: false, ircop: $ircop);
        $logger = $this->createStub(LoggerInterface::class);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('OPCMD', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    #[Test]
    public function operOnlyCommandWithoutPermissionRejectsNonOperNonIrcop(): void
    {
        $sender = new SenderView('UID1', 'RegularUser', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('ROLE', true, null, 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(99);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'error.oper_only' => 'IRC Operators only.',
                default => $id,
            }
        );

        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $logger = $this->createStub(LoggerInterface::class);

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, 'IRC Operators only.', 'NOTICE');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('ROLE', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function permissionCheckDeniesWhenUserLacksRequiredPermission(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, true, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('PERMCMD', false, 'operserv.admin', 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getLanguage')->willReturn('en');
        $account->method('getTimezone')->willReturn('UTC');

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'error.oper_only' => 'Oper only.',
                'error.permission_denied' => 'Permission denied.',
                default => $id,
            }
        );
        $accessHelper = $this->createAccessHelperForPermission(hasPermission: false);
        $logger = $this->createStub(LoggerInterface::class);

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, 'Permission denied.', 'NOTICE');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('PERMCMD', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function permissionCheckAllowsWhenUserHasRequiredPermission(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, true, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('PERMCMD', false, 'operserv.admin', 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(1);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);

        $rootUserRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('hasPermission')->willReturn(true);

        $accessHelper = new IrcopAccessHelper($rootUserRegistry, $ircopRepo, $roleRepo);
        $logger = $this->createStub(LoggerInterface::class);

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('operserv.admin', self::anything())
            ->willReturn(true);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('PERMCMD', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    #[Test]
    public function rootUserBypassesPermissionCheck(): void
    {
        $sender = new SenderView('UID1', 'RootNick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('PERMCMD', false, 'operserv.admin', 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $accessHelper = $this->createAccessHelperForRoot('RootNick');
        $logger = $this->createStub(LoggerInterface::class);

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('operserv.admin', self::anything())
            ->willReturn(true);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('PERMCMD', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndUserNotIdentified(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('NEEDID', false, 'IDENTIFIED', 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.not_identified' === $id ? 'Not identified' : $id
        );
        $accessHelper = $this->createAccessHelper();
        $logger = $this->createStub(LoggerInterface::class);

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('IDENTIFIED', self::anything())
            ->willReturn(false);

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, 'Not identified', 'NOTICE');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
        );

        $service->dispatch('NEEDID', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function minimumArgsCheckRejectsWhenNotEnoughArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, true, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('NEEDARGS', false, null, 2);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $params = []): string {
                $syntax = $params['%syntax%'] ?? '';

                return 'error.syntax' === $id ? 'Syntax: ' . (is_string($syntax) ? $syntax : '') : $id;
            }
        );
        $accessHelper = $this->createAccessHelper();
        $logger = $this->createStub(LoggerInterface::class);

        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with($sender->uid, self::stringContains('Syntax:'), 'NOTICE');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('NEEDARGS onlyone', $sender);

        self::assertNull($contextHolder->getContext());
    }

    #[Test]
    public function languageAndTimezoneResolvedFromAccount(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, true, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('TEST', false, null, 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('es');
        $account->method('getTimezone')->willReturn('Europe/Madrid');

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $accessHelper = $this->createAccessHelper();
        $logger = $this->createStub(LoggerInterface::class);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('TEST', $sender);

        $context = $contextHolder->getContext();
        self::assertInstanceOf(OperServContext::class, $context);
        self::assertSame('es', $context->getLanguage());
        self::assertSame('Europe/Madrid', $context->getTimezone());
    }

    #[Test]
    public function languageAndTimezoneUseDefaultsWhenNoAccount(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('TEST', false, null, 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $accessHelper = $this->createAccessHelper();
        $logger = $this->createStub(LoggerInterface::class);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'es',
            'Europe/Madrid',
            $logger,
        );

        $service->dispatch('TEST', $sender);

        $context = $contextHolder->getContext();
        self::assertInstanceOf(OperServContext::class, $context);
        self::assertSame('es', $context->getLanguage());
        self::assertSame('Europe/Madrid', $context->getTimezone());
    }

    #[Test]
    public function commandLoggedCorrectly(): void
    {
        $sender = new SenderView('UID1', 'TestNick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('LOGTEST', false, null, 0);
        $handler->setExecuteCallback(static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        });

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslationInterface::class);
        $accessHelper = $this->createAccessHelper();
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('debug')
            ->with('OperServ: TestNick executed LOGTEST [args: 2]');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $this->createServiceNicks(),
            'en',
            'UTC',
            $logger,
        );

        $service->dispatch('LOGTEST arg1 arg2', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    #[Test]
    public function dispatchesCommandExecutedEventWithSuccessfulOutcome(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, true, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $auditableHandler = new class($contextHolder) implements OperServCommandInterface, IrcopAuditableCommandInterface {
            public function __construct(private readonly OperServTestContextHolder $holder) {}

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

            public function getRequiredPermission(): string
            {
                return 'OPERSERV_ADMIN';
            }

            public function execute(OperServContext $context): CommandOutcome
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
            ->with('OPERSERV_ADMIN', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => $auditableHandler === $event->command
                && 'operserv' === $event->serviceName
                && 'Nick' === $event->operatorNick
                && 'AUDITCMD' === $event->commandName
                && 'OPERSERV_ADMIN' === $event->permission
                && true === $event->outcome?->success
                && 'TargetNick' === $event->outcome->auditData?->target));

        $registry = new OperServCommandRegistry([$auditableHandler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $notifier->method('getServiceKey')->willReturn('operserv');

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $notifier,
            $this->createStub(UserMessageTypeResolverInterface::class),
            $this->createStub(TranslationInterface::class),
            $this->createAccessHelper(),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('AUDITCMD', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    #[Test]
    public function dispatchesCommandExecutedEventWithRejectedOutcome(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new OperServTestContextHolder();
        $contextHolder->context = null;

        $auditableHandler = new class($contextHolder) implements OperServCommandInterface, IrcopAuditableCommandInterface {
            public function __construct(private readonly OperServTestContextHolder $holder) {}

            public function getName(): string
            {
                return 'FAILCMD';
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
                return 'OPERSERV_ADMIN';
            }

            public function execute(OperServContext $context): CommandOutcome
            {
                $this->holder->context = $context;

                return CommandOutcome::rejected();
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('OPERSERV_ADMIN', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (CommandExecutedEvent $event): bool => false === $event->outcome?->success));

        $registry = new OperServCommandRegistry([$auditableHandler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $this->createStub(OperServNotifierInterface::class),
            $this->createStub(UserMessageTypeResolverInterface::class),
            $this->createStub(TranslationInterface::class),
            $this->createAccessHelper(),
            $this->createServiceNicks(),
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
            $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker,
            $eventDispatcher,
        );

        $service->dispatch('FAILCMD', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->getContext());
    }

    private function createMockCommandHandler(
        string $name,
        bool $isOperOnly,
        ?string $requiredPermission,
        int $minArgs,
    ): OperServCallbackCommandInterface {
        return new class($name, $isOperOnly, $requiredPermission, $minArgs) implements OperServCallbackCommandInterface {
            private ?Closure $executeCallback = null;

            public function __construct(
                private readonly string $name,
                private readonly bool $isOperOnly,
                private readonly ?string $requiredPermission,
                private readonly int $minArgs,
            ) {}

            public function setExecuteCallback(Closure $callback): void
            {
                $this->executeCallback = $callback;
            }

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
                return $this->minArgs;
            }

            public function getSyntaxKey(): string
            {
                return 'syntax.' . strtolower($this->name);
            }

            public function getHelpKey(): string
            {
                return 'help.' . strtolower($this->name);
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short.' . strtolower($this->name);
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return $this->isOperOnly;
            }

            public function getRequiredPermission(): ?string
            {
                return $this->requiredPermission;
            }

            public function execute(OperServContext $context): void
            {
                if (null !== $this->executeCallback) {
                    ($this->executeCallback)($context);
                }
            }
        };
    }

    /**
     * Creates an OperServService with the required authorization dependencies.
     */
    private function createOperServService(
        OperServCommandRegistry $registry,
        RegisteredNickRepositoryInterface $nickRepository,
        OperServNotifierInterface $notifier,
        UserMessageTypeResolverInterface $messageTypeResolver,
        TranslationInterface $translator,
        IrcopAccessHelper $accessHelper,
        ServiceNicknameRegistry $serviceNicks,
        string $defaultLanguage = 'en',
        string $defaultTimezone = 'UTC',
        ?LoggerInterface $logger = null,
        ?AuthorizationContextInterface $authorizationContext = null,
        ?AuthorizationCheckerInterface $authorizationChecker = null,
        ?EventBusInterface $eventDispatcher = null,
    ): OperServService {
        return new OperServService(
            $registry,
            $nickRepository,
            new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), $defaultLanguage),
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
            $serviceNicks,
            $authorizationContext ?? $this->createStub(AuthorizationContextInterface::class),
            $authorizationChecker ?? $this->createStub(AuthorizationCheckerInterface::class),
            $eventDispatcher ?? $this->createStub(EventBusInterface::class),
            $defaultLanguage,
            $defaultTimezone,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
