<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\HelpFormatterContextAdapter;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Application\Security\PermissionRegistry;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    private function createAdapter(NickServContext $context): HelpFormatterContextAdapter
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = new PermissionRegistry([]);

        return new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );
    }

    private function createContext(
        ?SenderView $sender,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        NickServCommandRegistry $registry,
    ): NickServContext {
        return new NickServContext(
            $sender ?? new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
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

    #[Test]
    public function replyDelegatesToContext(): void
    {
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        $adapter->reply('test.key', ['%param%' => 'value']);

        self::assertSame(['test.key'], $messages);
    }

    #[Test]
    public function replyRawDelegatesToContext(): void
    {
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        $adapter->replyRaw('Raw message');

        self::assertSame(['Raw message'], $messages);
    }

    #[Test]
    public function transDelegatesToContext(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $this->createStub(NickServNotifierInterface::class),
            $translator,
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertSame('help.key', $adapter->trans('help.key'));
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsRegistryAll(): void
    {
        $cmd = $this->createCommandStub('REGISTER');
        $registry = new NickServCommandRegistry([$cmd]);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = $this->createAdapter($context);

        $commands = iterator_to_array($adapter->getCommandsForGeneralHelp());

        self::assertCount(1, $commands);
        self::assertSame('REGISTER', $commands[0]->getName());
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseWhenOperOnlyAndSenderNotOper(): void
    {
        $command = $this->createCommandStub('ADMIN', true);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, false),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueWhenOperOnlyAndSenderIsOper(): void
    {
        $command = $this->createCommandStub('ADMIN', true);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, true),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueWhenNotOperOnly(): void
    {
        $command = $this->createCommandStub('INFO', false);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyWhenSenderNull(): void
    {
        $cmd = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $registry = new NickServCommandRegistry([$cmd]);
        $context = new NickServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = $this->createAdapter($context);

        $commands = iterator_to_array($adapter->getIrcopCommands());

        self::assertSame([], $commands);
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyWhenAccountNull(): void
    {
        $cmd = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $registry = new NickServCommandRegistry([$cmd]);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, false),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = $this->createAdapter($context);

        $commands = iterator_to_array($adapter->getIrcopCommands());

        self::assertSame([], $commands);
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseWhenSenderNull(): void
    {
        $context = new NickServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseWhenAccountNull(): void
    {
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, false),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseWhenNotOper(): void
    {
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true, false),
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new NickServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->hasIrcopAccess());
    }

    private function createCommandStub(string $name, bool $operOnly = false): NickServCommandInterface
    {
        return new class($name, $operOnly) implements NickServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly bool $operOnly,
            ) {
            }

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
                return '';
            }

            public function getHelpKey(): string
            {
                return '';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return '';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return $this->operOnly;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): void
            {
            }
        };
    }

    private function createIrcopCommandStub(string $name, string $permission): NickServCommandInterface
    {
        return new class($name, $permission) implements NickServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $permission,
            ) {
            }

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
                return '';
            }

            public function getHelpKey(): string
            {
                return '';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return '';
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
                return $this->permission;
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): void
            {
            }
        };
    }
}
