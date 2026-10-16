<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc;

use App\ChanServ\Application\Port\In\ChannelRegistration;
use App\CRegServ\Adapter\In\Irc\Command\AprobarCommand;
use App\CRegServ\Adapter\In\Irc\Command\RegistraCommand;
use App\CRegServ\Adapter\In\Irc\Command\SaregisterCommand;
use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationCheckerInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\CRegServ\Application\Security\CRegServPermission;
use App\CRegServ\Application\UseCase\ApproveChannelRegistration\ApproveChannelRegistrationHandlerInterface;
use App\CRegServ\Application\UseCase\RequestChannelRegistration\RequestChannelRegistrationHandlerInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Tests\CRegServ\Fake\OperOnlyCommandDouble;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function iterator_to_array;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    /** @var list<CRegServCommandInterface> */
    private array $commands = [];

    /** @var list<string> */
    private array $grantedPermissions = [];

    #[Test]
    public function replyDelegatesToContext(): void
    {
        $sent = [];
        $adapter = $this->createAdapter($this->createContext($this->createNotifier($sent)));

        $adapter->reply('test.key', ['param' => 'value']);

        self::assertCount(1, $sent);
        self::assertSame('test.key', $sent[0]['msg']);
    }

    #[Test]
    public function replyRawDelegatesToContext(): void
    {
        $sent = [];
        $adapter = $this->createAdapter($this->createContext($this->createNotifier($sent)));

        $adapter->replyRaw('raw message');

        self::assertCount(1, $sent);
        self::assertSame('raw message', $sent[0]['msg']);
    }

    #[Test]
    public function transDelegatesToContext(): void
    {
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertSame('trans.key', $adapter->trans('trans.key', ['param' => 'value']));
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsEveryRegisteredCommandOnce(): void
    {
        $this->commands = [$this->createCommand('REGISTRA'), $this->createCommand('ESTADO')];
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertSame($this->commands, iterator_to_array($adapter->getCommandsForGeneralHelp(), false));
    }

    #[Test]
    public function shouldShowHidesCregservIrcopPermissionsEvenForOperators(): void
    {
        $adapter = $this->createAdapter($this->createContext(
            $this->createStub(CRegServNotifierInterface::class),
            isOper: true,
        ));

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp(new AprobarCommand(
            $this->createStub(ApproveChannelRegistrationHandlerInterface::class),
        )));
    }

    #[Test]
    public function shouldShowAcceptsNonIrcopPermissionCommands(): void
    {
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp(new RegistraCommand(
            $this->createStub(RequestChannelRegistrationHandlerInterface::class),
        )));
    }

    #[Test]
    public function shouldShowGatesPermissionlessOperOnlyCommandsOnSenderMode(): void
    {
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp(new OperOnlyCommandDouble()));

        $operAdapter = $this->createAdapter($this->createContext(
            $this->createStub(CRegServNotifierInterface::class),
            isOper: true,
        ));

        self::assertTrue($operAdapter->shouldShowCommandInGeneralHelp(new OperOnlyCommandDouble()));
    }

    #[Test]
    public function canViewCommandAcceptsPublicCommands(): void
    {
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertTrue($adapter->canViewCommandInHelp(new RegistraCommand(
            $this->createStub(RequestChannelRegistrationHandlerInterface::class),
        )));
    }

    #[Test]
    public function canViewCommandAcceptsIrcopCommandsTheSenderIsGranted(): void
    {
        $this->grantedPermissions = [CRegServPermission::APROBAR];
        $command = new AprobarCommand($this->createStub(ApproveChannelRegistrationHandlerInterface::class));
        $this->commands = [$command];
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertTrue($adapter->canViewCommandInHelp($command));
    }

    #[Test]
    public function canViewCommandRejectsHiddenCommandsOutsideTheIrcopList(): void
    {
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertFalse($adapter->canViewCommandInHelp(new OperOnlyCommandDouble()));
    }

    #[Test]
    public function getHelpGroupsExposesTheCregServGrouping(): void
    {
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        $groups = $adapter->getHelpGroups();

        self::assertSame([
            ['group_key' => 'help.group.requests', 'commands' => ['REGISTRA', 'ESTADO', 'CANCEL'], 'admin' => false, 'subgroup' => false],
            ['group_key' => 'help.ircop_group.approval', 'commands' => ['APROBAR', 'DENEGAR'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.queue', 'commands' => ['PENDIENTES', 'DENEGADOS', 'DROP'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.forced', 'commands' => ['SAREGISTER'], 'admin' => true, 'subgroup' => true],
        ], $groups);
    }

    #[Test]
    public function getIrcopCommandsYieldsOnlyGrantedCregservPermissions(): void
    {
        $this->grantedPermissions = [CRegServPermission::APROBAR];
        $aprobar = new AprobarCommand($this->createStub(ApproveChannelRegistrationHandlerInterface::class));
        $saregister = new SaregisterCommand($this->createStub(ChannelRegistration::class));
        $this->commands = [$aprobar, $saregister, $this->createCommand('REGISTRA')];
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertSame([$aprobar], iterator_to_array($adapter->getIrcopCommands()));
    }

    #[Test]
    public function hasIrcopAccessReturnsTrueWhenAnyIrcopPermissionIsGranted(): void
    {
        $this->grantedPermissions = [CRegServPermission::APROBAR];
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertTrue($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseWhenNothingIsGranted(): void
    {
        $adapter = $this->createAdapter($this->createContext($this->createStub(CRegServNotifierInterface::class)));

        self::assertFalse($adapter->hasIrcopAccess());
    }

    private function createAdapter(CRegServContext $context): HelpFormatterContextAdapter
    {
        return new HelpFormatterContextAdapter($context, $this->createAuthorizationChecker());
    }

    private function createContext(CRegServNotifierInterface $notifier, bool $isOper = false): CRegServContext
    {
        return new CRegServContext(
            new SenderView('UID1', 'SenderNick', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: $isOper),
            null,
            'HELP',
            [],
            $notifier,
            $this->createTranslator(),
            'en',
            'UTC',
            'NOTICE',
            new CRegServCommandRegistry($this->commands),
            $this->createServiceNicks(),
        );
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    private function createAuthorizationChecker(): CRegServAuthorizationCheckerInterface
    {
        $checker = $this->createStub(CRegServAuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(
            fn (string $permission): bool => in_array($permission, $this->grantedPermissions, true),
        );

        return $checker;
    }

    /** @param list<array{uid: string, msg: string, type: string}> $sent */
    private function createNotifier(array &$sent): CRegServNotifierInterface
    {
        $notifier = $this->createStub(CRegServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CReG');
        $notifier->method('sendMessage')->willReturnCallback(
            static function (string $uid, string $msg, string $type) use (&$sent): void {
                $sent[] = ['uid' => $uid, 'msg' => $msg, 'type' => $type];
            },
        );

        return $notifier;
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $nickservProvider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
        $cregservProvider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'cregserv';
            }

            public function getNickname(): string
            {
                return 'CReG';
            }
        };

        return new ServiceNicknameRegistry([$nickservProvider, $cregservProvider]);
    }

    private function createCommand(string $name): CRegServCommandInterface
    {
        return new class($name) implements CRegServCommandInterface {
            public function __construct(private string $name) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'stub.syntax';
            }

            public function getHelpKey(): string
            {
                return 'stub.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'stub.short';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(CRegServContext $context): ?CommandOutcome
            {
                return null;
            }
        };
    }
}
