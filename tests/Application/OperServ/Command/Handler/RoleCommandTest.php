<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RoleCommand::class)]
final class RoleCommandTest extends TestCase
{
    private function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $adminRepo = $this->createStub(\App\Domain\OperServ\Repository\OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $adminRepo, $roleRepo);
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslatorInterface $translator,
        OperServCommandRegistry $registry,
        IrcopAccessHelper $accessHelper,
    ): OperServContext {
        return new OperServContext(
            $sender,
            null,
            'ROLE',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $accessHelper,
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function nonRootUserGetsRootOnlyError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(false);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.root_only', $messages);
    }

    #[Test]
    public function unknownSubcommandGetsUnknownSubError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.unknown_sub', $messages);
    }

    #[Test]
    public function delWithMissingArgsGetsSyntaxError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function addWithMissingArgsGetsSyntaxError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['ADD'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function addDuplicateRoleGetsAlreadyExistsError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $existingRole = OperRole::create('ADMIN', 'Admin role', true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($existingRole);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['ADD', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.already_exists', $messages);
    }

    #[Test]
    public function delNonExistentRoleGetsNotFoundError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['DEL', 'UNKNOWN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function listWithNoRolesGetsEmptyMessage(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findAll')->willReturn([]);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.list.empty', $messages);
    }

    #[Test]
    public function permsWithUnknownActionGetsUnknownActionError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.unknown_action', $messages);
    }

    #[Test]
    public function permsWithMissingArgsGetsSyntaxError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function permsWithNonExistentRoleGetsNotFoundError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'UNKNOWN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function getNameReturnsRole(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame('ROLE', $cmd->getName());
    }

    #[Test]
    public function getSyntaxKeyReturnsRoleSyntax(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame('role.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsRoleHelp(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame('role.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsTwo(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame(2, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsRoleShort(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame('role.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getSubCommandHelpReturnsArray(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $subs = $cmd->getSubCommandHelp();

        self::assertCount(4, $subs);
        self::assertSame('LIST', $subs[0]['name']);
        self::assertSame('ADD', $subs[1]['name']);
        self::assertSame('DEL', $subs[2]['name']);
        self::assertSame('PERMS', $subs[3]['name']);
    }

    #[Test]
    public function addSuccessCreatesRoleAndCallsSave(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $role) use (&$savedRoles): void {
            $savedRoles[] = $role;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['ADD', 'TESTROLE'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.add.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame('TESTROLE', $savedRoles[0]->getName());
        self::assertSame('Custom role', $savedRoles[0]->getDescription());
        self::assertFalse($savedRoles[0]->isProtected());
    }

    #[Test]
    public function addWithDescriptionCreatesRoleWithCustomDescription(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $role) use (&$savedRoles): void {
            $savedRoles[] = $role;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['ADD', 'CUSTOM', 'My custom role description'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.add.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame('CUSTOM', $savedRoles[0]->getName());
        self::assertSame('My custom role description', $savedRoles[0]->getDescription());
    }

    #[Test]
    public function delSuccessNonProtectedRemovesRole(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);
        $removedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('remove')->willReturnCallback(static function (OperRole $r) use (&$removedRoles): void {
            $removedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['DEL', 'CUSTOM'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.del.done', $messages);
        self::assertCount(1, $removedRoles);
        self::assertSame('CUSTOM', $removedRoles[0]->getName());
    }

    #[Test]
    public function delWithProtectedRoleReturnsRoleProtected(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('remove');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['DEL', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.protected', $messages);
    }

    #[Test]
    public function listSuccessWithRolesDisplaysRolesWithNamesAndDescriptions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role1 = OperRole::create('ADMIN', 'Administrator role', true);
        $role2 = OperRole::create('MODERATOR', 'Moderator role', false);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findAll')->willReturn([$role1, $role2]);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.list.header', $messages);
        self::assertStringContainsString('ADMIN', $messages[1]);
        self::assertStringContainsString('Administrator role', $messages[1]);
        self::assertStringContainsString('[PROTECTED]', $messages[1]);
        self::assertStringContainsString('MODERATOR', $messages[2]);
        self::assertStringContainsString('Moderator role', $messages[2]);
    }

    #[Test]
    public function permsListSuccessShowsRolePermissions(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);
        $perm1 = OperPermission::create('operserv.admin.add', 'Admin add');
        $perm2 = OperPermission::create('operserv.kill.local', 'Kill local');
        $role->addPermission($perm1);
        $role->addPermission($perm2);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.header', $messages);
        self::assertContains('  operserv.admin.add', $messages);
        self::assertContains('  operserv.kill.local', $messages);
    }

    #[Test]
    public function permsListEmptyReturnsRolePermsListEmpty(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.empty', $messages);
    }

    #[Test]
    public function permsAddSuccessAddsPermissionToRole(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
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

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.add.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertTrue($savedRoles[0]->hasPermission('operserv.admin.add'));
    }

    #[Test]
    public function permsAddWithMissingArgsReturnsErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'ADD'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function permsAddNonExistentPermissionReturnsRolePermsNotFound(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn(null);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'ADD', 'nonexistent.perm'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.not_found', $messages);
    }

    #[Test]
    public function permsAddAlreadyHasReturnsRolePermsAlreadyHas(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
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

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'ADD', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.already_has', $messages);
    }

    #[Test]
    public function permsDelSuccessRemovesPermissionFromRole(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
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

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'DEL', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.del.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertFalse($savedRoles[0]->hasPermission('operserv.admin.add'));
    }

    #[Test]
    public function permsDelWithMissingArgsReturnsErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function permsDelNonExistentPermReturnsRolePermsNotFound(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $role = OperRole::create('CUSTOM', 'Custom role', false);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $permRepo->method('findByName')->willReturn(null);
        $registry = new OperServCommandRegistry([]);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'DEL', 'nonexistent.perm'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.not_found', $messages);
    }

    #[Test]
    public function permsDelNotInRoleReturnsRolePermsDoesNotHave(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
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

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'CUSTOM', 'DEL', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.does_not_have', $messages);
    }

    #[Test]
    public function permsDelWithProtectedRoleReturnsRolePermsProtected(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
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

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'DEL', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.protected', $messages);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
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
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
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
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
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
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
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

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
