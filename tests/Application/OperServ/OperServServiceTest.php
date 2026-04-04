<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Security\AuthorizationCheckerInterface;
use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\OperServService;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Domain\IRC\Event\IrcopCommandExecutedEvent;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OperServService::class)]
final class OperServServiceTest extends TestCase
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
            $role = $this->createStub(\App\Domain\OperServ\Entity\OperRole::class);
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
            $this->createStub(TranslatorInterface::class),
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
        $translator = $this->createMock(TranslatorInterface::class);

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
    public function commandFoundExecutesHandler(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('TEST', false, null, 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())
            ->method('findByNick')
            ->with($sender->nick)
            ->willReturn(null);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
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

        $service->dispatch('TEST arg1 arg2', $sender);

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
        self::assertSame('TEST', $contextHolder->context->command);
        self::assertSame(['arg1', 'arg2'], $contextHolder->context->args);
        self::assertSame($sender, $contextHolder->context->sender);
    }

    #[Test]
    public function operOnlyCommandRejectsNonOper(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', false, 'OPERSERV_OPCMD', 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied.' : $id
        );
        $accessHelper = $this->createAccessHelper(isRoot: false);
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

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function operOnlyCommandRejectsNonOperWithNullAccount(): void
    {
        $sender = new SenderView('UID1', 'UnregisteredUser', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('ADMINCMD', false, 'OPERSERV_ADMINCMD', 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::once())
            ->method('findByNick')
            ->with($sender->nick)
            ->willReturn(null);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied.' : $id
        );
        $accessHelper = $this->createAccessHelper(isRoot: false);
        $logger = $this->createStub(LoggerInterface::class);

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('OPERSERV_ADMINCMD', self::anything())
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

        $service->dispatch('ADMINCMD', $sender);

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function operOnlyCommandRejectsNonOperWithRegisteredNonIrcopAccount(): void
    {
        $sender = new SenderView('UID1', 'RegisteredNonIrcop', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', false, 'OPERSERV_OPCMD', 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied.' : $id
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

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function operOnlyCommandAllowsOperViaIsOperFlag(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'ident', 'host', 'cloak', '127.0.0.1', false, true, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', true, null, 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    #[Test]
    public function operOnlyCommandAllowsOperViaAccessHelperIsRoot(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', true, null, 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    #[Test]
    public function operOnlyCommandAllowsOperViaIrcopLookup(): void
    {
        $sender = new SenderView('UID1', 'IrcopNick', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('OPCMD', true, null, 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

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
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    #[Test]
    public function permissionCheckDeniesWhenUserLacksRequiredPermission(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('PERMCMD', false, 'operserv.admin', 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => 'error.permission_denied' === $id ? 'Permission denied.' : $id
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

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function permissionCheckAllowsWhenUserHasRequiredPermission(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('PERMCMD', false, 'operserv.admin', 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $role = $this->createStub(\App\Domain\OperServ\Entity\OperRole::class);
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
        $translator = $this->createStub(TranslatorInterface::class);

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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    #[Test]
    public function rootUserBypassesPermissionCheck(): void
    {
        $sender = new SenderView('UID1', 'RootNick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('PERMCMD', false, 'operserv.admin', 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    #[Test]
    public function repliesNotIdentifiedWhenRequiredPermissionIdentifiedAndUserNotIdentified(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('NEEDID', false, 'IDENTIFIED', 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function minimumArgsCheckRejectsWhenNotEnoughArgs(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('NEEDARGS', false, null, 2);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => 'error.syntax' === $id ? 'Syntax: ' . ($params['%syntax%'] ?? '') : $id
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

        self::assertNull($contextHolder->context);
    }

    #[Test]
    public function languageAndTimezoneResolvedFromAccount(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('TEST', false, null, 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

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
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
        self::assertSame('es', $contextHolder->context->getLanguage());
        self::assertSame('Europe/Madrid', $contextHolder->context->getTimezone());
    }

    #[Test]
    public function languageAndTimezoneUseDefaultsWhenNoAccount(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', false, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('TEST', false, null, 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
        self::assertSame('es', $contextHolder->context->getLanguage());
        self::assertSame('Europe/Madrid', $contextHolder->context->getTimezone());
    }

    #[Test]
    public function commandLoggedCorrectly(): void
    {
        $sender = new SenderView('UID1', 'TestNick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $handler = $this->createMockCommandHandler('LOGTEST', false, null, 0);
        $handler->executeCallback = static function (OperServContext $ctx) use ($contextHolder): void {
            $contextHolder->context = $ctx;
        };

        $registry = new OperServCommandRegistry([$handler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->method('resolve')->willReturn('NOTICE');
        $translator = $this->createStub(TranslatorInterface::class);
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    #[Test]
    public function dispatchesIrcopCommandExecutedEventWhenHandlerIsAuditableAndHasPermission(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', '127.0.0.1', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        $auditableHandler = new class($contextHolder) implements OperServCommandInterface, AuditableCommandInterface {
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
                return 'OPERSERV_ADMIN';
            }

            public function execute(OperServContext $context): void
            {
                $this->auditData = new IrcopAuditData(
                    target: 'TargetNick',
                    targetHost: 'user@host',
                    targetIp: '127.0.0.1',
                    reason: 'test reason',
                    extra: ['key' => 'value'],
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
            ->with('OPERSERV_ADMIN', self::anything())
            ->willReturn(true);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (IrcopCommandExecutedEvent $event): bool => 'Nick' === $event->operatorNick
                && 'AUDITCMD' === $event->commandName
                && 'OPERSERV_ADMIN' === $event->permission
                && 'TargetNick' === $event->target
                && 'user@host' === $event->targetHost
                && '127.0.0.1' === $event->targetIp
                && 'test reason' === $event->reason
                && ['key' => 'value'] === $event->extra));

        $registry = new OperServCommandRegistry([$auditableHandler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $this->createStub(OperServNotifierInterface::class),
            $this->createStub(UserMessageTypeResolverInterface::class),
            $this->createStub(TranslatorInterface::class),
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    #[Test]
    public function doesNotDispatchIrcopCommandEventWhenAuditDataIsNull(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'ident', 'host', 'cloak', 'ip', true, false, '001', 'cloak');
        $contextHolder = new stdClass();
        $contextHolder->context = null;

        // Handler implements AuditableCommandInterface but getAuditData returns null (command failed)
        $auditableHandler = new class($contextHolder) implements OperServCommandInterface, AuditableCommandInterface {
            private ?IrcopAuditData $auditData = null;

            public function __construct(private readonly stdClass $holder)
            {
            }

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

            public function getRequiredPermission(): ?string
            {
                return 'OPERSERV_ADMIN';
            }

            public function execute(OperServContext $context): void
            {
                $this->holder->context = $context;
            }

            public function getAuditData(object $context): ?IrcopAuditData
            {
                return null; // Command failed, no audit data
            }
        };

        $authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('OPERSERV_ADMIN', self::anything())
            ->willReturn(true);

        // Event should NOT be dispatched when auditData is null
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())
            ->method('dispatch');

        $registry = new OperServCommandRegistry([$auditableHandler]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $service = $this->createOperServService(
            $registry,
            $nickRepository,
            $this->createStub(OperServNotifierInterface::class),
            $this->createStub(UserMessageTypeResolverInterface::class),
            $this->createStub(TranslatorInterface::class),
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

        self::assertInstanceOf(OperServContext::class, $contextHolder->context);
    }

    private function createMockCommandHandler(
        string $name,
        bool $isOperOnly,
        ?string $requiredPermission,
        int $minArgs,
    ): object {
        $contextHolder = new stdClass();
        $contextHolder->executeCallback = null;

        return new class($name, $isOperOnly, $requiredPermission, $minArgs, $contextHolder) implements OperServCommandInterface {
            public $executeCallback;

            public function __construct(
                private readonly string $name,
                private readonly bool $isOperOnly,
                private readonly ?string $requiredPermission,
                private readonly int $minArgs,
                private readonly stdClass $contextHolder,
            ) {
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
        TranslatorInterface $translator,
        IrcopAccessHelper $accessHelper,
        ServiceNicknameRegistry $serviceNicks,
        string $defaultLanguage = 'en',
        string $defaultTimezone = 'UTC',
        ?LoggerInterface $logger = null,
        ?AuthorizationContextInterface $authorizationContext = null,
        ?AuthorizationCheckerInterface $authorizationChecker = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): OperServService {
        return new OperServService(
            $registry,
            $nickRepository,
            $notifier,
            $messageTypeResolver,
            $translator,
            $accessHelper,
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
