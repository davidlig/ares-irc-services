<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\Handler\RoleModesHandler;
use App\Application\OperServ\Command\Handler\RoleOperclassHandler;
use App\Application\OperServ\Command\Handler\RolePermissionsHandler;
use App\Application\OperServ\Command\Handler\RoleVhostHandler;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\ForcedVhostApplier;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(RoleCommand::class)]
#[CoversClass(RoleModesHandler::class)]
final class RoleModesHandlerTest extends RoleHandlerTestCase
{
    #[Test]
    public function modesWithMissingArgsGetsSyntaxError(): void
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
        $cmd->execute($this->createContext($sender, ['MODES'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function modesWithNonExistentRoleGetsNotFoundError(): void
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
        $cmd->execute($this->createContext($sender, ['MODES', 'UNKNOWN', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function modesWithUnknownActionGetsUnknownActionError(): void
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
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.unknown_action', $messages);
    }

    #[Test]
    public function modesViewEmptyReturnsModesViewEmpty(): void
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'NEWROLE', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.view.empty', $messages);
    }

    #[Test]
    public function modesViewSuccessShowsModes(): void
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
        $role->changeUserModes(['o', 'a']);

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
        $role = OperRole::create('CUSTOM', 'Custom role', false);
        $role->changeUserModes(['o', 'a']);

        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setValue($role, 1);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'CUSTOM', 'SET'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.cleared', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame([], $savedRoles[0]->getUserModes());
    }

    #[Test]
    public function modesSetSuccessSetsValidModes(): void
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
        $role = OperRole::create('ADMIN', 'Admin role', true);

        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setValue($role, 1);

        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $r) use (&$savedRoles): void {
            $savedRoles[] = $r;
        });
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'SET', '+oaN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame(['o', 'a', 'N'], $savedRoles[0]->getUserModes());
    }

    #[Test]
    public function modesSetWithInvalidModesReturnsError(): void
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
        $roleRepo->expects(self::never())->method('save');
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'SET', '+xyz'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.invalid_modes', $messages);
    }

    #[Test]
    public function modesSetWhenNotSupportedReturnsError(): void
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

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]), []);
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'SET', '+o'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.not_supported', $messages);
    }

    #[Test]
    public function modesSetWithNoProtocolModuleReturnsError(): void
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
        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setValue($role, 1);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeApplier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            $ircopRepo,
            $nickRepo,
            $this->createStub(NetworkUserLookupPort::class),
            new NullLogger(),
        );

        $nsNotifier = $this->createStub(NickServNotifierInterface::class);
        $vhostApplier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            new IdentifiedSessionRegistry(),
            $nsNotifier,
            $this->createStub(NetworkUserLookupPort::class),
            $connectionHolder,
            new VhostDisplayResolver(),
            new NullLogger(),
        );

        $cmd = new RoleCommand(
            $roleRepo,
            new RolePermissionsHandler($roleRepo, $permRepo, new PermissionRegistry([])),
            new RoleOperclassHandler(
                $roleRepo,
                $connectionHolder,
                new IrcopOperclassApplier(new IdentifiedSessionRegistry(), $connectionHolder, $ircopRepo, $nickRepo),
            ),
            new RoleModesHandler($roleRepo, $connectionHolder, $modeApplier),
            new RoleVhostHandler(
                $roleRepo,
                $vhostApplier,
                new VhostValidator(),
                $this->createStub(EventBusInterface::class),
            ),
            $accessHelper,
        );
        $cmd->execute($this->createContext($sender, ['MODES', 'ADMIN', 'SET', '+o'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.modes.set.no_irc_user_modes', $messages);
    }
}
