<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
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
        $adminRepo = $this->createStub(\App\Domain\OperServ\Repository\OperAdminRepositoryInterface::class);
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
    public function getNameReturnsRole(): void
    {
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new RoleCommand($roleRepo, $permRepo, $accessHelper);
        self::assertSame('ROLE', $cmd->getName());
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
}
