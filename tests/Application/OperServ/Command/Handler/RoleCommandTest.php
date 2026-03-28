<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\SenderView;
use App\Application\Port\UserModeSupportInterface;
use App\Application\Security\PermissionProviderInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RoleCommand::class)]
final class RoleCommandTest extends TestCase
{
    private function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $adminRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $adminRepo, $roleRepo);
    }

    private function createCmd(
        OperRoleRepositoryInterface $roleRepo,
        OperPermissionRepositoryInterface $permRepo,
        IrcopAccessHelper $accessHelper,
        PermissionRegistry $permissionRegistry,
    ): RoleCommand {
        $userModeSupport = $this->createStub(UserModeSupportInterface::class);
        $userModeSupport->method('getIrcOpUserModes')->willReturn(['o', 'a', 'N', 'O']);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getUserModeSupport')->willReturn($userModeSupport);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeApplier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            $ircopRepo,
            $nickRepo,
            $this->createStub(\App\Application\Port\NetworkUserLookupPort::class),
            new NullLogger(),
        );

        return new RoleCommand(
            $roleRepo,
            $permRepo,
            $accessHelper,
            $permissionRegistry,
            $connectionHolder,
            new IdentifiedSessionRegistry(),
            $modeApplier,
            $this->createStub(EventDispatcherInterface::class),
        );
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'UNKNOWN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
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

        self::assertCount(5, $subs);
        self::assertSame('LIST', $subs[0]['name']);
        self::assertSame('ADD', $subs[1]['name']);
        self::assertSame('DEL', $subs[2]['name']);
        self::assertSame('PERMS', $subs[3]['name']);
        self::assertSame('MODES', $subs[4]['name']);
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.list.empty', $messages);
    }

    #[Test]
    public function permsListShowsAvailablePermissionsWhenNoneAssigned(): void
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
                ) {
                }

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
    public function permsListShowsAllAssignedWhenRoleHasAllPermissions(): void
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
                ) {
                }

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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['PERMS', 'ADMIN', 'DEL', 'operserv.admin.add'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.perms.protected', $messages);
    }

    #[Test]
    public function modesWithMissingArgsGetsSyntaxError(): void
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function modesWithNonExistentRoleGetsNotFoundError(): void
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'UNKNOWN', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function modesWithUnknownActionGetsUnknownActionError(): void
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.unknown_action', $messages);
    }

    #[Test]
    public function modesViewEmptyReturnsModesViewEmpty(): void
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

        $role = OperRole::create('NEWROLE', 'New role', false);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'NEWROLE', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.view.empty', $messages);
    }

    #[Test]
    public function modesViewSuccessShowsModes(): void
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
        $role->setUserModes(['o', 'a']);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.view.header', $messages);
        self::assertContains('role.modes.view.line', $messages);
    }

    #[Test]
    public function modesSetClearsModesWhenEmptyArg(): void
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
        $role = OperRole::create('CUSTOM', 'Custom role', false);
        $role->setUserModes(['o', 'a']);

        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setAccessible(true);
        $roleIdProp->setValue($role, 1);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $userModeSupport = $this->createStub(UserModeSupportInterface::class);
        $userModeSupport->method('getIrcOpUserModes')->willReturn(['o', 'a', 'N', 'O']);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getUserModeSupport')->willReturn($userModeSupport);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByRoleId')->with(1)->willReturn([]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $modeApplier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            $ircopRepo,
            $nickRepo,
            $this->createStub(\App\Application\Port\NetworkUserLookupPort::class),
            new NullLogger(),
        );

        $cmd = new RoleCommand(
            $roleRepo,
            $permRepo,
            $accessHelper,
            new PermissionRegistry([]),
            $connectionHolder,
            new IdentifiedSessionRegistry(),
            $modeApplier,
            $this->createStub(EventDispatcherInterface::class),
        );
        $cmd->execute($this->createContext($sender, ['MODES', 'CUSTOM', 'SET'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.cleared', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame([], $savedRoles[0]->getUserModes());
    }

    #[Test]
    public function modesSetSuccessSetsValidModes(): void
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
        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setAccessible(true);
        $roleIdProp->setValue($role, 1);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $userModeSupport = $this->createStub(UserModeSupportInterface::class);
        $userModeSupport->method('getIrcOpUserModes')->willReturn(['o', 'a', 'N', 'O']);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getUserModeSupport')->willReturn($userModeSupport);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects(self::once())->method('findByRoleId')->with(1)->willReturn([]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $modeApplier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            $ircopRepo,
            $nickRepo,
            $this->createStub(\App\Application\Port\NetworkUserLookupPort::class),
            new NullLogger(),
        );

        $cmd = new RoleCommand(
            $roleRepo,
            $permRepo,
            $accessHelper,
            new PermissionRegistry([]),
            $connectionHolder,
            new IdentifiedSessionRegistry(),
            $modeApplier,
            $this->createStub(EventDispatcherInterface::class),
        );
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'SET', '+oaN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame(['o', 'a', 'N'], $savedRoles[0]->getUserModes());
    }

    #[Test]
    public function modesSetWithInvalidModesReturnsError(): void
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
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'SET', '+xyz'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.invalid_modes', $messages);
    }

    #[Test]
    public function modesSetWithNoProtocolModuleReturnsError(): void
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

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeApplier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            $ircopRepo,
            $nickRepo,
            $this->createStub(\App\Application\Port\NetworkUserLookupPort::class),
            new NullLogger(),
        );

        $cmd = new RoleCommand(
            $roleRepo,
            $permRepo,
            $accessHelper,
            new PermissionRegistry([]),
            $connectionHolder,
            new IdentifiedSessionRegistry(),
            $modeApplier,
            $this->createStub(EventDispatcherInterface::class),
        );
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'SET', '+o'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.no_irc_user_modes', $messages);
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
