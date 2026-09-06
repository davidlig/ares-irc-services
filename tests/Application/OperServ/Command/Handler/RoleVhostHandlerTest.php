<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\Handler\RoleVhostHandler;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Irc\Application\Port\In\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

#[CoversClass(RoleCommand::class)]
#[CoversClass(RoleVhostHandler::class)]
final class RoleVhostHandlerTest extends RoleHandlerTestCase
{
    #[Test]
    public function vhostWithMissingArgsGetsSyntaxError(): void
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
        $cmd->execute($this->createContext($sender, ['VHOST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function vhostWithNonExistentRoleGetsNotFoundError(): void
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
        $cmd->execute($this->createContext($sender, ['VHOST', 'UNKNOWN', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function vhostWithUnknownActionGetsUnknownActionError(): void
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
        $cmd->execute($this->createContext($sender, ['VHOST', 'ADMIN', 'INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.unknown_action', $messages);
    }

    #[Test]
    public function vhostViewEmptyReturnsVhostViewEmpty(): void
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
        $cmd->execute($this->createContext($sender, ['VHOST', 'NEWROLE', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.view.empty', $messages);
    }

    #[Test]
    public function vhostViewSuccessShowsPattern(): void
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
        $role->changeForcedVhostPattern('admin.ares');

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $permRepo = $this->createStub(OperPermissionRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]));
        $cmd->execute($this->createContext($sender, ['VHOST', 'ADMIN', 'VIEW'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.view.header', $messages);
        self::assertContains('role.vhost.view.line', $messages);
    }

    #[Test]
    public function vhostSetWithValidPatternSetsPattern(): void
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

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]), eventDispatcher: $eventDispatcher);
        $cmd->execute($this->createContext($sender, ['VHOST', 'CUSTOM', 'SET', 'admin.ares'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.set.done', $messages);
        self::assertCount(1, $savedRoles);
        self::assertSame('admin.ares', $savedRoles[0]->getForcedVhostPattern());
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(OperRoleForcedVhostChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame(1, $dispatchedEvents[0]->roleId);
        self::assertSame('admin.ares', $dispatchedEvents[0]->pattern);
    }

    #[Test]
    public function vhostSetWithInvalidPatternReturnsError(): void
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
        $cmd->execute($this->createContext($sender, ['VHOST', 'ADMIN', 'SET', 'invalidpattern'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.set.invalid', $messages);
    }

    #[Test]
    public function vhostSetWithSpecialCharsReturnsError(): void
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
        $cmd->execute($this->createContext($sender, ['VHOST', 'ADMIN', 'SET', 'test@invalid'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.set.invalid', $messages);
    }

    #[Test]
    public function vhostSetWithOffClearsPattern(): void
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
        $role->changeForcedVhostPattern('admin.ares');

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

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]), eventDispatcher: $eventDispatcher);
        $cmd->execute($this->createContext($sender, ['VHOST', 'CUSTOM', 'SET', 'OFF'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.set.cleared', $messages);
        self::assertCount(1, $savedRoles);
        self::assertNull($savedRoles[0]->getForcedVhostPattern());
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(OperRoleForcedVhostChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame(1, $dispatchedEvents[0]->roleId);
        self::assertNull($dispatchedEvents[0]->pattern);
    }

    #[Test]
    public function vhostSetWithEmptyArgClearsPattern(): void
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
        $role->changeForcedVhostPattern('admin.ares');

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

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $cmd = $this->createCmd($roleRepo, $permRepo, $accessHelper, new PermissionRegistry([]), eventDispatcher: $eventDispatcher);
        $cmd->execute($this->createContext($sender, ['VHOST', 'CUSTOM', 'SET'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('role.vhost.set.cleared', $messages);
        self::assertCount(1, $savedRoles);
        self::assertNull($savedRoles[0]->getForcedVhostPattern());
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(OperRoleForcedVhostChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame(1, $dispatchedEvents[0]->roleId);
        self::assertNull($dispatchedEvents[0]->pattern);
    }
}
