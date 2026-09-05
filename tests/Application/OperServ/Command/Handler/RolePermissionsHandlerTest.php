<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\Handler\RolePermissionsHandler;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Security\PermissionProviderInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RoleCommand::class)]
#[CoversClass(RolePermissionsHandler::class)]
final class RolePermissionsHandlerTest extends RoleHandlerTestCase
{
    #[Test]
    public function permsWithUnknownActionGetsUnknownActionError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.unknown_action', $messages);
    }

    #[Test]
    public function permsWithMissingArgsGetsSyntaxError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function permsWithNonExistentRoleGetsNotFoundError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'UNKNOWN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function permsListSuccessShowsRolePermissions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);
        $perm1 = OperPermission::create('operserv.admin.add', 'Admin add');
        $perm2 = OperPermission::create('operserv.kill', 'Kill users');
        $role->addPermission($perm1);
        $role->addPermission($perm2);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.header', $messages);
        self::assertContains('  operserv.admin.add', $messages);
        self::assertContains('  operserv.kill', $messages);
    }

    #[Test]
    public function permsListEmptyReturnsRolePermsListEmpty(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.empty', $messages);
    }

    #[Test]
    public function permsListShowsAvailablePermissionsWhenNoneAssigned(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('NEWROLE', 'New role', false);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('TestService', ['PERM_ONE', 'PERM_TWO']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'NEWROLE', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.header', $messages);
        self::assertContains('role.perms.list.none_assigned', $messages);
        self::assertContains('role.perms.list.available', $messages);
        self::assertContains('  PERM_ONE', $messages);
        self::assertContains('  PERM_TWO', $messages);
    }

    #[Test]
    public function permsListShowsPermissionsWithDescriptions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = [], string $domain = 'operserv', string $locale = 'en'): string {
            if ('permissions.operserv.kill' === $id) {
                return 'Disconnect a user from the network';
            }
            if ('permissions.operserv.other' === $id) {
                return 'Other permission description';
            }

            return $id;
        });
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('TESTROLE', 'Test role', false);
        $perm = OperPermission::create('operserv.kill', 'Kill users');
        $role->addPermission($perm);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('OperServ', ['operserv.kill', 'operserv.other']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'TESTROLE', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.header', $messages);
        self::assertContains('role.perms.list.assigned', $messages);
        self::assertContains('  operserv.kill - Disconnect a user from the network', $messages);
        self::assertContains('role.perms.list.available', $messages);
        self::assertContains('  operserv.other - Other permission description', $messages);
    }

    #[Test]
    public function permsListResolvesDescriptionsFromServiceDomains(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = [], string $domain = 'operserv', string $locale = 'en'): string {
            if ('permissions.nickserv.drop' === $id && 'nickserv' === $domain) {
                return 'Delete a registered nickname (DROP)';
            }
            if ('permissions.chanserv.suspend' === $id && 'chanserv' === $domain) {
                return 'Suspend or unsuspend a channel (SUSPEND/UNSUSPEND)';
            }
            if ('permissions.operserv.kill' === $id && 'operserv' === $domain) {
                return 'Disconnect a nickname from the network (KILL)';
            }

            return $id;
        });
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CROSSROLE', 'Cross domain role', false);
        $permKill = OperPermission::create('operserv.kill', 'Kill users');
        $permDrop = OperPermission::create('nickserv.drop', 'Drop nick');
        $role->addPermission($permKill);
        $role->addPermission($permDrop);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('OperServ', ['operserv.kill']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
            new readonly class('NickServ', ['nickserv.drop']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
            new readonly class('ChanServ', ['chanserv.suspend']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CROSSROLE', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.assigned', $messages);
        self::assertContains('  operserv.kill - Disconnect a nickname from the network (KILL)', $messages);
        self::assertContains('  nickserv.drop - Delete a registered nickname (DROP)', $messages);
        self::assertContains('role.perms.list.available', $messages);
        self::assertContains('  chanserv.suspend - Suspend or unsuspend a channel (SUSPEND/UNSUSPEND)', $messages);
    }

    #[Test]
    public function permsListFallsBackToOperServDomainWhenServiceDomainMissing(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = [], string $domain = 'operserv', string $locale = 'en'): string {
            if ('permissions.unknown.perm' === $id && 'unknown' === $domain) {
                return 'permissions.unknown.perm';
            }
            if ('permissions.unknown.perm' === $id && 'operserv' === $domain) {
                return 'Fallback description from operserv';
            }

            return $id;
        });
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('FALLBACK', 'Fallback role', false);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('Unknown', ['unknown.perm']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'FALLBACK', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.available', $messages);
        self::assertContains('  unknown.perm - Fallback description from operserv', $messages);
    }

    #[Test]
    public function permsListShowsAllAssignedWhenRoleHasAllPermissions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('FULLROLE', 'Full role', false);
        $perm1 = OperPermission::create('PERM_ONE', 'Permission one');
        $role->addPermission($perm1);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('TestService', ['PERM_ONE']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'FULLROLE', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.header', $messages);
        self::assertContains('role.perms.list.assigned', $messages);
        self::assertContains('  PERM_ONE', $messages);
        self::assertContains('role.perms.list.all_assigned', $messages);
    }

    #[Test]
    public function permsAddSuccessAddsPermissionToRole(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);
        $perm = OperPermission::create('operserv.admin.add', 'Admin add');

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn($perm);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.add.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertTrue($savedRoles[0]->hasPermission('operserv.admin.add'));
    }

    #[Test]
    public function permsAddWithMissingArgsReturnsErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'ADD'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function permsAddNonExistentPermissionReturnsRolePermsNotFound(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn(null);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'nonexistent.perm'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.not_found', $messages);
    }

    #[Test]
    public function permsAddAlreadyHasReturnsRolePermsAlreadyHas(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $perm = OperPermission::create('operserv.admin.add', 'Admin add');
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->addPermission($perm);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn($perm);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'ADD', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.already_has', $messages);
    }

    #[Test]
    public function permsDelSuccessRemovesPermissionFromRole(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $perm = OperPermission::create('operserv.admin.add', 'Admin add');
        $role = OperRole::create('CUSTOM', 'Custom role', false);
        $role->addPermission($perm);

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn($perm);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'DEL', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.del.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertFalse($savedRoles[0]->hasPermission('operserv.admin.add'));
    }

    #[Test]
    public function permsDelWithMissingArgsReturnsErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function permsDelNonExistentPermReturnsRolePermsNotFound(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn(null);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'DEL', 'nonexistent.perm'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.not_found', $messages);
    }

    #[Test]
    public function permsDelNotInRoleReturnsRolePermsDoesNotHave(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $perm = OperPermission::create('operserv.admin.add', 'Admin add');
        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn($perm);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'DEL', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.does_not_have', $messages);
    }

    #[Test]
    public function permsDelWithProtectedRoleReturnsRolePermsProtected(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $perm = OperPermission::create('operserv.admin.add', 'Admin add');
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->addPermission($perm);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn($perm);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'DEL', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.protected', $messages);
    }

    #[Test]
    public function permsAddAllSuccessAddsAllPermissions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $savedRoles = [];
        $savedPermissions = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createMock(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn(null);
        $permRepo->expects(self::exactly(2))->method('save')->willReturnCallback(static function (OperPermission $p) use (&$savedPermissions): void {
            $savedPermissions[] = $p;
        });
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('TestService', ['PERM_ONE', 'PERM_TWO']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'ALL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.add.all_done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertTrue($savedRoles[0]->hasPermission('PERM_ONE'));
        self::assertTrue($savedRoles[0]->hasPermission('PERM_TWO'));
        self::assertCount(2, $savedPermissions);
    }

    #[Test]
    public function permsAddAllSkipsWhenRoleAlreadyHasAllPermissions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $perm1 = OperPermission::create('PERM_ONE', 'Permission one');
        $perm2 = OperPermission::create('PERM_TWO', 'Permission two');
        $role = OperRole::create('FULLROLE', 'Full role', false);
        $role->addPermission($perm1);
        $role->addPermission($perm2);

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('TestService', ['PERM_ONE', 'PERM_TWO']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'FULLROLE', 'ADD', 'ALL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.add.all_skipped', $messages);
    }

    #[Test]
    public function permsAddAllEmptyWhenNoPermissionsAvailable(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'ALL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.add.all_empty', $messages);
    }

    #[Test]
    public function permsClearSuccessRemovesAllPermissions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $perm1 = OperPermission::create('PERM_ONE', 'Permission one');
        $perm2 = OperPermission::create('PERM_TWO', 'Permission two');
        $role = OperRole::create('CUSTOM', 'Custom role', false);
        $role->addPermission($perm1);
        $role->addPermission($perm2);

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'CLEAR'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.clear.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame([], $savedRoles[0]->getPermissions());
    }

    #[Test]
    public function permsClearEmptyReturnsRolePermsClearEmpty(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'CLEAR'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.clear.empty', $messages);
    }

    #[Test]
    public function permsAddAutoCreatesPermissionFromRegistry(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $savedRoles = [];
        $savedPermissions = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createMock(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn(null);
        $permRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperPermission $p) use (&$savedPermissions): void {
            $savedPermissions[] = $p;
        });
        $registry = new OperServCommandRegistry([]);

        $permissionRegistry = new PermissionRegistry([
            new readonly class('TestService', ['operserv.kill']) implements PermissionProviderInterface {
                public function __construct(
                    private string $serviceName,
                    private array $permissions,
                ) {}

                public function getServiceName(): string
                {
                    return $this->serviceName;
                }

                public function getPermissions(): array
                {
                    return $this->permissions;
                }
            },
        ]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, $permissionRegistry);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'operserv.kill'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.add.done', $messages);
        self::assertCount(1, $savedPermissions);
        self::assertSame('operserv.kill', $savedPermissions[0]->getName());
        self::assertTrue($savedRoles[0]->hasPermission('operserv.kill'));
    }

    #[Test]
    public function permsAddNonExistentInRegistryReturnsNotFound(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createMock(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn(null);
        $permRepo->expects(self::never())->method('save');
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'nonexistent.perm'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.not_found', $messages);
    }
}
