<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServHelpFormatterContextAdapter;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OperServHelpFormatterContextAdapter::class)]
final class OperServHelpFormatterContextAdapterTest extends TestCase
{
    private function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createContext(
        bool $isRoot = false,
        ?OperServCommandRegistry $registry = null,
    ): OperServContext {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper($isRoot);
        $registry ??= new OperServCommandRegistry([]);

        return new OperServContext(
            $sender,
            null,
            'TEST',
            [],
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

    private function createCommandMock(bool $isOperOnly, string $name = 'TESTCMD'): OperServCommandInterface
    {
        return new class($isOperOnly, $name) implements OperServCommandInterface {
            public function __construct(
                private readonly bool $operOnly,
                private readonly string $cmdName,
            ) {
            }

            public function getName(): string
            {
                return $this->cmdName;
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
                return 'testcmd.syntax';
            }

            public function getHelpKey(): string
            {
                return 'testcmd.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'testcmd.short';
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

            public function execute(OperServContext $c): void
            {
            }
        };
    }

    #[Test]
    public function replyDelegatesToOperServContext(): void
    {
        $capturedMessages = [];
        $context = $this->createContext();
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(static function (string $uid, string $message) use (&$capturedMessages): void {
                $capturedMessages[] = $message;
            });
        $notifier->method('getNick')->willReturn('OperServ');

        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => 'translated.' . $id);
        $accessHelper = $this->createAccessHelper(false);
        $registry = new OperServCommandRegistry([]);

        $contextWithMock = new OperServContext(
            $sender,
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $accessHelper,
            $this->createServiceNicks(),
        );

        $adapter = new OperServHelpFormatterContextAdapter($contextWithMock);
        $adapter->reply('test.key', ['param' => 'value']);

        self::assertCount(1, $capturedMessages);
        self::assertSame('translated.test.key', $capturedMessages[0]);
    }

    #[Test]
    public function replyRawDelegatesToOperServContext(): void
    {
        $capturedMessages = [];
        $context = $this->createContext();
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects($this->once())
            ->method('sendMessage')
            ->willReturnCallback(static function (string $uid, string $message) use (&$capturedMessages): void {
                $capturedMessages[] = $message;
            });
        $notifier->method('getNick')->willReturn('OperServ');

        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $translator = $this->createStub(TranslatorInterface::class);
        $accessHelper = $this->createAccessHelper(false);
        $registry = new OperServCommandRegistry([]);

        $contextWithMock = new OperServContext(
            $sender,
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $accessHelper,
            $this->createServiceNicks(),
        );

        $adapter = new OperServHelpFormatterContextAdapter($contextWithMock);
        $adapter->replyRaw('raw message');

        self::assertCount(1, $capturedMessages);
        self::assertSame('raw message', $capturedMessages[0]);
    }

    #[Test]
    public function transDelegatesToOperServContext(): void
    {
        $context = $this->createContext();
        $adapter = new OperServHelpFormatterContextAdapter($context);

        $result = $adapter->trans('translation.key', ['param' => 'value']);

        self::assertSame('translation.key', $result);
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsRegistryCommands(): void
    {
        $command1 = $this->createCommandMock(false, 'CMD1');
        $command2 = $this->createCommandMock(true, 'CMD2');
        $registry = new OperServCommandRegistry([$command1, $command2]);
        $context = $this->createContext(false, $registry);

        $adapter = new OperServHelpFormatterContextAdapter($context);
        $commands = $adapter->getCommandsForGeneralHelp();

        self::assertCount(2, $commands);
        self::assertContains($command1, $commands);
        self::assertContains($command2, $commands);
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueForNonOperCommand(): void
    {
        $context = $this->createContext(false);
        $adapter = new OperServHelpFormatterContextAdapter($context);
        $command = $this->createCommandMock(false);

        $result = $adapter->shouldShowCommandInGeneralHelp($command);

        self::assertTrue($result);
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueForOperCommandWhenUserIsRoot(): void
    {
        $context = $this->createContext(true);
        $adapter = new OperServHelpFormatterContextAdapter($context);
        $command = $this->createCommandMock(true);

        $result = $adapter->shouldShowCommandInGeneralHelp($command);

        self::assertTrue($result);
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseForOperCommandWhenUserIsNotRoot(): void
    {
        $context = $this->createContext(false);
        $adapter = new OperServHelpFormatterContextAdapter($context);
        $command = $this->createCommandMock(true);

        $result = $adapter->shouldShowCommandInGeneralHelp($command);

        self::assertFalse($result);
    }
}
