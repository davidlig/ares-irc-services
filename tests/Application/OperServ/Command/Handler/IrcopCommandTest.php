<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\AdminAccessHelper;
use App\Application\OperServ\Command\Handler\IrcopCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperAdminRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IrcopCommand::class)]
final class IrcopCommandTest extends TestCase
{
    private function createAccessHelper(bool $isRoot): AdminAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new AdminAccessHelper($rootRegistry, $adminRepo, $roleRepo);
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslatorInterface $translator,
        OperServCommandRegistry $registry,
        AdminAccessHelper $accessHelper,
    ): OperServContext {
        return new OperServContext(
            $sender,
            null,
            'IRCOP',
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
        $sender = new SenderView('UID1', 'NonRootUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(false);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.unknown_sub', $messages);
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['ADD', 'TestNick'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function addWithUnregisteredNickGetsNickNotRegisteredError(): void
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['ADD', 'TestNick', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.nick_not_registered', $messages);
    }

    #[Test]
    public function listWithNoAdminsGetsEmptyMessage(): void
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $adminRepo->method('findAll')->willReturn([]);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.list.empty', $messages);
    }

    #[Test]
    public function getNameReturnsIrcop(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        self::assertSame('IRCOP', $cmd->getName());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getSubCommandHelpReturnsArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $adminRepo = $this->createStub(OperAdminRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $adminRepo, $roleRepo, $accessHelper);
        $subs = $cmd->getSubCommandHelp();

        self::assertCount(3, $subs);
        self::assertSame('ADD', $subs[0]['name']);
        self::assertSame('DEL', $subs[1]['name']);
        self::assertSame('LIST', $subs[2]['name']);
    }
}
