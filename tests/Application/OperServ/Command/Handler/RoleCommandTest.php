<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RoleCommand::class)]
final class RoleCommandTest extends RoleHandlerTestCase
{
    #[Test]
    public function exposesAccessHelper(): void
    {
        $accessHelper = $this->createAccessHelper(true);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);
        $command = $this->createCmd($roleRepository, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]));

        self::assertSame($accessHelper, $command->getAccessHelper());
    }

    #[Test]
    public function nonRootUserGetsRootOnlyError(): void
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
        $accessHelper = $this->createAccessHelper(false);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.root_only', $messages);
    }

    #[Test]
    public function unknownSubcommandGetsUnknownSubError(): void
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
        $cmd->execute($this->createContext($sender, ['INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.unknown_sub', $messages);
    }

    #[Test]
    public function operclassIsHiddenOnProtocolsWithoutOperclassSupport(): void
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
        $cmd->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.unknown_sub', $messages);
    }

    #[Test]
    public function delWithMissingArgsGetsSyntaxError(): void
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
        $cmd->execute($this->createContext($sender, ['DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function addWithMissingArgsGetsSyntaxError(): void
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
        $cmd->execute($this->createContext($sender, ['ADD'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function addDuplicateRoleGetsAlreadyExistsError(): void
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

        $existingRole = OperRole::create('ADMIN', 'Admin role', true);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($existingRole);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['ADD', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.already_exists', $messages);
    }

    #[Test]
    public function delNonExistentRoleGetsNotFoundError(): void
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
        $cmd->execute($this->createContext($sender, ['DEL', 'UNKNOWN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function listWithNoRolesGetsEmptyMessage(): void
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
        $roleRepo->method('findAll')->willReturn([]);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.list.empty', $messages);
    }

    #[Test]
    public function getNameReturnsRole(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertSame('ROLE', $cmd->getName());
    }

    #[Test]
    public function getSyntaxKeyReturnsRoleSyntax(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertSame('role.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsRoleHelp(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertSame('role.help', $cmd->getHelpKey());
    }

    #[Test]
    public function supportedProtocolUsesOperclassHelpKeys(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]), supportsOperclass: true);

        self::assertSame('role.syntax_operclass', $cmd->getSyntaxKey());
        self::assertSame('role.help_operclass', $cmd->getHelpKey());
        self::assertCount(7, $cmd->getSubCommandHelp());
    }

    #[Test]
    public function getOrderReturnsTwo(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertSame(2, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsRoleShort(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertSame('role.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getSubCommandHelpReturnsArray(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $subs = $cmd->getSubCommandHelp();

        self::assertCount(6, $subs);
        self::assertSame('LIST', $subs[0]['name']);
        self::assertSame('ADD', $subs[1]['name']);
        self::assertSame('DEL', $subs[2]['name']);
        self::assertSame('PERMS', $subs[3]['name']);
        self::assertSame('MODES', $subs[4]['name']);
        self::assertSame('VHOST', $subs[5]['name']);
    }

    #[Test]
    public function addSuccessCreatesRoleAndCallsSave(): void
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

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $role) use (&$savedRoles): void {
            $savedRoles[] = $role;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $savedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $role) use (&$savedRoles): void {
            $savedRoles[] = $role;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['ADD', 'CUSTOM', 'My custom role description'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.add.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame('CUSTOM', $savedRoles[0]->getName());
        self::assertSame('My custom role description', $savedRoles[0]->getDescription());
    }

    #[Test]
    public function delSuccessNonProtectedRemovesRole(): void
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
        $removedRoles = [];
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('remove')->willReturnCallback(static function (OperRole $r) use (&$removedRoles): void {
            $removedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['DEL', 'CUSTOM'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.del.done', $messages);
        self::assertCount(1, $removedRoles);
        self::assertSame('CUSTOM', $removedRoles[0]->getName());
    }

    #[Test]
    public function delWithProtectedRoleReturnsRoleProtected(): void
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
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('remove');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['DEL', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.protected', $messages);
    }

    #[Test]
    public function listSuccessWithRolesDisplaysRolesWithNamesAndDescriptions(): void
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

        $role1 = OperRole::create('ADMIN', 'Administrator role', true);
        $role2 = OperRole::create('MODERATOR', 'Moderator role', false);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findAll')->willReturn([$role1, $role2]);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.list.header', $messages);
        self::assertStringContainsString('ADMIN', $messages[1]);
        self::assertStringContainsString('Administrator role', $messages[1]);
        self::assertStringContainsString('[PROTECTED]', $messages[1]);
        self::assertStringContainsString('MODERATOR', $messages[2]);
        self::assertStringContainsString('Moderator role', $messages[2]);
    }

    #[Test]
    public function getSubCommandHelpReturnsVhost(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $subs = $cmd->getSubCommandHelp();

        $vhostFound = false;
        foreach ($subs as $sub) {
            if ('VHOST' === $sub['name']) {
                $vhostFound = true;
                break;
            }
        }

        self::assertTrue($vhostFound, 'VHOST should be in subcommands');
    }
}
