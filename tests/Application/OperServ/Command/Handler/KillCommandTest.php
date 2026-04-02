<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\Handler\KillCommand;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\RootUserRegistry;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(KillCommand::class)]
final class KillCommandTest extends TestCase
{
    private function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createModeApplier(): IrcopModeApplier
    {
        $identifiedRegistry = new \App\Application\NickServ\IdentifiedSessionRegistry();
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        return new IrcopModeApplier($identifiedRegistry, $connectionHolder, $ircopRepo, $nickRepo, $userLookup, new NullLogger());
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
            'KILL',
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

    private function createServiceNicks(): \App\Application\ApplicationPort\ServiceNicknameRegistry
    {
        $provider = new class('operserv', 'OperServ') implements \App\Application\ApplicationPort\ServiceNicknameProviderInterface {
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

        return new \App\Application\ApplicationPort\ServiceNicknameRegistry([$provider]);
    }

    #[Test]
    public function getNameReturnsKill(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('KILL', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('kill.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('kill.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsCorrectOrder(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(10, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('kill.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsTrue(): void
    {
        $cmd = $this->createCommand();
        self::assertTrue($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsKill(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(OperServPermission::KILL, $cmd->getRequiredPermission());
    }

    #[Test]
    public function missingReasonArgGetsSyntaxError(): void
    {
        self::assertSame(2, $this->createCommand()->getMinArgs());
        self::assertSame('kill.syntax', $this->createCommand()->getSyntaxKey());
    }

    #[Test]
    public function userNotOnlineGetsUserNotOnlineError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $cmd = $this->createCommandWithMockedDeps(userLookup: $userLookup);
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['BadUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));

        self::assertStringContainsString('kill.user_not_online', $messages[0]);
    }

    #[Test]
    public function targetIsRootGetsProtectedRootError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'RootUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $rootRegistry = new RootUserRegistry('RootUser');
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class),
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);

        $cmd = $this->createCommandWithMockedDeps(
            userLookup: $userLookup,
            rootRegistry: $rootRegistry,
        );
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['RootUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));

        self::assertStringContainsString('kill.protected_root', $messages[0]);
    }

    #[Test]
    public function targetIsIrcopGetsProtectedIrcopError(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);

        $nick = RegisteredNick::createPending('OperUser', 'hash', 'test@test.com', 'en', new DateTimeImmutable('+1 day'));
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setAccessible(true);
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('OPER', 'Oper role');
        $ircop = OperIrcop::create(42, $role, 1, null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $cmd = $this->createCommandWithMockedDeps(
            userLookup: $userLookup,
            nickRepo: $nickRepo,
            ircopRepo: $ircopRepo,
        );
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['OperUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));

        self::assertStringContainsString('kill.protected_ircop', $messages[0]);
    }

    #[Test]
    public function targetIsOperButNotIdentifiedIsNotProtected(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('killUser');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithMockedDeps(
            userLookup: $userLookup,
            connectionHolder: $connectionHolder,
        );
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['OperUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));

        self::assertStringContainsString('kill.done', $messages[0]);
    }

    #[Test]
    public function targetIsOperIdentifiedButNickNotInRepoIsNotProtected(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('killUser');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithMockedDeps(
            userLookup: $userLookup,
            nickRepo: $nickRepo,
            connectionHolder: $connectionHolder,
        );
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['OperUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));

        self::assertStringContainsString('kill.done', $messages[0]);
    }

    #[Test]
    public function successKillExecutesKill(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'BadUser', 'i', 'h', 'c', 'c29saWFkZWQh', false, false, 'SID1', 'c', 'i', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())->method('killUser');

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithMockedDeps(
            userLookup: $userLookup,
            connectionHolder: $connectionHolder,
        );
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['BadUser', 'Flooding', 'channels'], $notifier, $translator, $registry, $accessHelper));

        self::assertStringContainsString('kill.done', $messages[0]);
    }

    #[Test]
    public function noProtocolModuleLogsErrorAndReturns(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'BadUser', 'i', 'h', 'c', 'c29saWFkZWQh', false, false, 'SID1', 'c', 'i', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $cmd = $this->createCommandWithMockedDeps(
            userLookup: $userLookup,
            connectionHolder: $connectionHolder,
        );
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['BadUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));

        self::assertEmpty($messages);
    }

    #[Test]
    public function noServerSidLogsErrorAndReturns(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $target = new SenderView('UID2', 'BadUser', 'i', 'h', 'c', 'c29saWFkZWQh', false, false, 'SID1', 'c', 'i', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($target);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn(null);

        $cmd = $this->createCommandWithMockedDeps(
            userLookup: $userLookup,
            connectionHolder: $connectionHolder,
        );
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext($sender, ['BadUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));

        self::assertEmpty($messages);
    }

    #[Test]
    public function nullSenderReturnsEarly(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $translator = $this->createStub(TranslatorInterface::class);
        $accessHelper = $this->createAccessHelper(false);

        $cmd = $this->createCommand();
        $registry = new OperServCommandRegistry([]);
        $cmd->execute($this->createContext(null, ['BadUser', 'Flooding'], $notifier, $translator, $registry, $accessHelper));
    }

    private function createCommand(): KillCommand
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessHelper = $this->createAccessHelper(false);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $logger = new NullLogger();

        return new KillCommand(
            $userLookup,
            $rootRegistry,
            $ircopRepo,
            $nickRepo,
            $accessHelper,
            $connectionHolder,
            $logger,
        );
    }

    private function createCommandWithMockedDeps(
        ?NetworkUserLookupPort $userLookup = null,
        ?RootUserRegistry $rootRegistry = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?ActiveConnectionHolderInterface $connectionHolder = null,
    ): KillCommand {
        return new KillCommand(
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $rootRegistry ?? new RootUserRegistry(''),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createAccessHelper(false),
            $connectionHolder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            new NullLogger(),
        );
    }
}
