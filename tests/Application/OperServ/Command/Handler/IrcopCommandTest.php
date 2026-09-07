<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\IrcopCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(IrcopCommand::class)]
final class IrcopCommandTest extends TestCase
{
    #[Test]
    public function exposesAccessHelper(): void
    {
        $accessHelper = $this->createAccessHelper(true);
        $command = new IrcopCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class),
            $accessHelper,
            $this->createModeApplier(),
            $this->createOperclassApplier(),
            $this->createStub(EventBusInterface::class),
        );

        self::assertSame($accessHelper, $command->getAccessHelper());
    }

    private function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createModeApplier(): IrcopModeApplier
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        return new IrcopModeApplier($identifiedRegistry, $connectionHolder, $ircopRepo, $nickRepo, $userLookup, new NullLogger());
    }

    private function createOperclassApplier(): IrcopOperclassApplier
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);

        return new IrcopOperclassApplier($identifiedRegistry, $connectionHolder, $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(RegisteredNickRepositoryInterface::class));
    }

    /** @param list<string> $args */
    private function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslationInterface $translator,
        OperServCommandRegistry $registry,
        IrcopAccessHelper $accessHelper,
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
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function nonRootUserGetsRootOnlyError(): void
    {
        $sender = new SenderView('UID1', 'NonRootUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(false);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'INVALID'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.unknown_sub', $messages);
    }

    #[Test]
    public function singleArgumentWithoutListReturnsSyntaxError(): void
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick'], $notifier, $translator, $registry, $accessHelper));

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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'ADD'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function addWithUnregisteredNickGetsNickNotRegisteredError(): void
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'ADD', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.nick_not_registered', $messages);
    }

    #[Test]
    public function canonicalAddWithUnregisteredNickGetsNickNotRegisteredError(): void
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand(
            $nickRepo,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class),
            $accessHelper,
            $this->createModeApplier(),
            $this->createOperclassApplier(),
            $this->createStub(EventBusInterface::class),
        );
        $cmd->execute($this->createContext($sender, ['ADD', 'TestNick', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.nick_not_registered', $messages);
    }

    #[Test]
    public function canonicalDelWithUnregisteredNickGetsNickNotRegisteredError(): void
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand(
            $nickRepo,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class),
            $accessHelper,
            $this->createModeApplier(),
            $this->createOperclassApplier(),
            $this->createStub(EventBusInterface::class),
        );
        $cmd->execute($this->createContext($sender, ['DEL', 'TestNick'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.nick_not_registered', $messages);
    }

    #[Test]
    public function listWithNoAdminsGetsEmptyMessage(): void
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findAll')->willReturn([]);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.list.empty', $messages);
    }

    #[Test]
    public function getNameReturnsIrcop(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        self::assertSame('IRCOP', $cmd->getName());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getSubCommandHelpReturnsArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $subs = $cmd->getSubCommandHelp();

        self::assertCount(3, $subs);
        self::assertSame('ADD', $subs[0]['name']);
        self::assertSame('DEL', $subs[1]['name']);
        self::assertSame('LIST', $subs[2]['name']);
    }

    #[Test]
    public function getSyntaxKeyReturnsIrcopSyntax(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));

        self::assertSame('ircop.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsIrcopHelp(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));

        self::assertSame('ircop.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsOne(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));

        self::assertSame(1, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsIrcopShort(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));

        self::assertSame('ircop.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));

        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(true);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function addSuccessCreatesIrcop(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('ADMIN', 'Admin role');
        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setValue($role, 1);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);

        $registry = new OperServCommandRegistry([]);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $eventDispatcher);
        $cmd->execute($this->createContext($sender, ['TestNick', 'ADD', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.add.done', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(OperIrcopChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame(42, $dispatchedEvents[0]->nickId);
        self::assertSame('TestNick', $dispatchedEvents[0]->nickname);
    }

    #[Test]
    public function addWithInactiveAccountReturnsNickNotActive(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'ADD', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.nick_not_active', $messages);
    }

    #[Test]
    public function addWithUnknownRoleReturnsUnknownRole(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn(null);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'ADD', 'UNKNOWN_ROLE'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.unknown_role', $messages);
    }

    #[Test]
    public function addWhenAlreadyAdminSameRoleReturnsAlreadyAdmin(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('ADMIN', 'Admin role');
        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setValue($role, 1);

        $existingIrcop = OperIrcop::create(42, $role, 1, null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($existingIrcop);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($role);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'ADD', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.already_admin', $messages);
    }

    #[Test]
    public function addRoleChangeChangesRole(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $oldRole = OperRole::create('OPER', 'Oper role');
        $oldRoleRefl = new ReflectionClass($oldRole);
        $oldRoleIdProp = $oldRoleRefl->getProperty('id');
        $oldRoleIdProp->setValue($oldRole, 1);

        $newRole = OperRole::create('ADMIN', 'Admin role');
        $newRoleRefl = new ReflectionClass($newRole);
        $newRoleIdProp = $newRoleRefl->getProperty('id');
        $newRoleIdProp->setValue($newRole, 2);

        $existingIrcop = OperIrcop::create(42, $oldRole, 1, null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($existingIrcop);
        $ircopRepo->expects(self::once())->method('save');

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('findByName')->willReturn($newRole);
        $registry = new OperServCommandRegistry([]);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $eventDispatcher);
        $cmd->execute($this->createContext($sender, ['TestNick', 'ADD', 'ADMIN'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.role_changed', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(OperIrcopChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame(42, $dispatchedEvents[0]->nickId);
        self::assertSame('TestNick', $dispatchedEvents[0]->nickname);
    }

    #[Test]
    public function delSuccessDeletesIrcop(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('ADMIN', 'Admin role');
        $ircop = OperIrcop::create(42, $role, 1, null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);
        $ircopRepo->expects(self::once())->method('remove');

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
            $dispatchedEvents[] = $event;

            return $event;
        });

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $eventDispatcher);
        $cmd->execute($this->createContext($sender, ['TestNick', 'DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.del.done', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(OperIrcopChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame(42, $dispatchedEvents[0]->nickId);
        self::assertSame('TestNick', $dispatchedEvents[0]->nickname);
    }

    #[Test]
    public function delWithMissingArgsReturnsSyntaxError(): void
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function delWithUnregisteredNickReturnsNickNotRegistered(): void
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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('error.nick_not_registered', $messages);
    }

    #[Test]
    public function delWithNonAdminUserReturnsNotAdmin(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['TestNick', 'DEL'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.not_admin', $messages);
    }

    #[Test]
    public function listWithAdminsShowsIrcopsList(): void
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

        $nick = RegisteredNick::createPending(
            'TestNick',
            'hash',
            'test@test.com',
            'en',
            new DateTimeImmutable('+1 day'),
            new DateTimeImmutable()
        );
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('ADMIN', 'Admin role');
        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setValue($role, 1);

        $ircop = OperIrcop::create(42, $role, 1, null);
        $ircopRefl = new ReflectionClass($ircop);
        $ircopIdProp = $ircopRefl->getProperty('id');
        $ircopIdProp->setValue($ircop, 100);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findAll')->willReturn([$ircop]);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.list.header', $messages);
        self::assertCount(2, $messages);
    }

    #[Test]
    public function listWithMissingNickByIdShowsNickId(): void
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

        $role = OperRole::create('ADMIN', 'Admin role');
        $roleRefl = new ReflectionClass($role);
        $roleIdProp = $roleRefl->getProperty('id');
        $roleIdProp->setValue($role, 1);

        $ircop = OperIrcop::create(42, $role, 1, null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findAll')->willReturn([$ircop]);

        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $registry = new OperServCommandRegistry([]);

        $cmd = new IrcopCommand($nickRepo, $ircopRepo, $roleRepo, $accessHelper, $this->createModeApplier(), $this->createOperclassApplier(), $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, ['LIST'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('ircop.list.header', $messages);
        self::assertCount(2, $messages);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
