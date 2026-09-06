<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\Handler\RoleOperclassHandler;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\TranslationInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Tests\Application\OperServ\RecordingOperclassActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

#[CoversClass(RoleCommand::class)]
#[CoversClass(RoleOperclassHandler::class)]
final class RoleOperclassHandlerTest extends RoleHandlerTestCase
{
    #[Test]
    public function operclassSetPersistsTheRoleValueOnSupportedProtocols(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);
        $role = OperRole::create('NETADMIN');
        new ReflectionClass($role)->getProperty('id')->setValue($role, 1);
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->with($role);

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'SET', 'services:netadmin'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertSame('services:netadmin', $role->getOperclass());
        self::assertContains('role.operclass.set.done', $messages);
    }

    #[Test]
    public function operclassWithMissingArgsGetsSyntaxError(): void
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
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->expects(self::never())->method('findByName');

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function operclassWithUnknownRoleGetsNotFoundError(): void
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

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS', 'GHOST', 'VIEW'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.not_found', $messages);
    }

    #[Test]
    public function operclassViewReportsAnEmptyAssignment(): void
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
        $role = OperRole::create('NETADMIN');
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'VIEW'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.view.empty', $messages);
    }

    #[Test]
    public function operclassViewReportsTheAssignedOperclass(): void
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
        $role = OperRole::create('NETADMIN');
        $role->changeOperclass('services:netadmin');
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'VIEW'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.view.line', $messages);
    }

    #[Test]
    public function operclassWithUnknownActionGetsUnknownActionError(): void
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
        $role = OperRole::create('NETADMIN');
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'FOO'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.unknown_action', $messages);
    }

    #[Test]
    public function operclassWithZeroArgsGetsSyntaxError(): void
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
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->expects(self::never())->method('findByName');

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function operclassListWhenProtocolReturnsNullReportsNotSupported(): void
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
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = null;

        $this->createCmd($this->createStub(OperRoleRepositoryInterface::class), $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'LIST'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.list.not_supported', $messages);
    }

    #[Test]
    public function operclassListWhenProtocolReturnsEmptyListReportsEmpty(): void
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
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = [];

        $this->createCmd($this->createStub(OperRoleRepositoryInterface::class), $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'LIST'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.list.empty', $messages);
    }

    #[Test]
    public function operclassListWhenProtocolReturnsListReportsHeaderAndLines(): void
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
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = ['locop', 'netadmin'];

        $this->createCmd($this->createStub(OperRoleRepositoryInterface::class), $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'LIST'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.list.header', $messages);
        self::assertContains('  locop', $messages);
        self::assertContains('  netadmin', $messages);
    }

    #[Test]
    public function operclassViewReportsAvailableOperclassesWhenPresent(): void
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
        $role = OperRole::create('NETADMIN');
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = ['locop', 'netadmin'];

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'VIEW'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.view.empty', $messages);
        self::assertContains('role.operclass.view.available', $messages);
    }

    #[Test]
    public function operclassListWithRoleNameActsAsAliasForView(): void
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
        $role = OperRole::create('NETADMIN');
        $role->changeOperclass('locop');
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = ['locop', 'netadmin'];

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'LIST'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.view.line', $messages);
        self::assertContains('role.operclass.view.available', $messages);
    }

    #[Test]
    public function operclassSetRejectsUnavailableOperclassWhenAvailableKnown(): void
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
        $role = OperRole::create('NETADMIN');
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::never())->method('save');
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = ['locop', 'netadmin'];

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'SET', 'invalid_class'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertContains('role.operclass.set.not_available', $messages);
        self::assertNull($role->getOperclass());
    }

    #[Test]
    public function operclassSetMatchesCaseInsensitiveAndStoresCanonicalName(): void
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
        $role = OperRole::create('NETADMIN');
        new ReflectionClass($role)->getProperty('id')->setValue($role, 1);
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->with($role);
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = ['netadmin'];

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'SET', 'NETADMIN'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertSame('netadmin', $role->getOperclass());
        self::assertContains('role.operclass.set.done', $messages);
    }

    #[Test]
    public function operclassSetClearsOperclassWithOff(): void
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
        $role = OperRole::create('NETADMIN');
        new ReflectionClass($role)->getProperty('id')->setValue($role, 1);
        $role->changeOperclass('netadmin');
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->with($role);

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), supportsOperclass: true)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'SET', 'OFF'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertNull($role->getOperclass());
        self::assertContains('role.operclass.set.cleared', $messages);
    }

    #[Test]
    public function operclassSetWhenAvailableIsNullAllowsSettingAnyOperclass(): void
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
        $role = OperRole::create('NETADMIN');
        new ReflectionClass($role)->getProperty('id')->setValue($role, 1);
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $roleRepo->expects(self::once())->method('save')->with($role);
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = null;

        $this->createCmd($roleRepo, $this->createStub(OperPermissionRepositoryInterface::class), $accessHelper, new PermissionRegistry([]), operclassActions: $actions)
            ->execute($this->createContext($sender, ['OPERCLASS', 'NETADMIN', 'SET', 'arbitrary:class'], $notifier, $translator, new OperServCommandRegistry([]), $accessHelper));

        self::assertSame('arbitrary:class', $role->getOperclass());
        self::assertContains('role.operclass.set.done', $messages);
    }
}
