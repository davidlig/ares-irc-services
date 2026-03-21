<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\HelpFormatterContextAdapter;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ChanServCommandRegistry $registry,
        $channelModeSupport = null,
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            $channelModeSupport ?? new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
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
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($notifier, $translator, new ChanServCommandRegistry([]));
        $adapter = new HelpFormatterContextAdapter($context);

        $adapter->reply('test.key', ['%param%' => 'value']);

        self::assertSame(['test.key'], $messages);
    }

    #[Test]
    public function replyRawDelegatesToContext(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($notifier, $translator, new ChanServCommandRegistry([]));
        $adapter = new HelpFormatterContextAdapter($context);

        $adapter->replyRaw('Raw message');

        self::assertSame(['Raw message'], $messages);
    }

    #[Test]
    public function transDelegatesToContext(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($this->createStub(ChanServNotifierInterface::class), $translator, new ChanServCommandRegistry([]));
        $adapter = new HelpFormatterContextAdapter($context);

        self::assertSame('help.key', $adapter->trans('help.key'));
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsRegistryAll(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'REGISTER';
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
                return null;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $commands = iterator_to_array($adapter->getCommandsForGeneralHelp());

        self::assertCount(1, $commands);
        self::assertSame('REGISTER', $commands[0]->getName());
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseForOperOnly(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'DROP';
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
                return true;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = new HelpFormatterContextAdapter($context);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueForNormalCommand(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'INFO';
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
                return null;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = new HelpFormatterContextAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpRespectsModeDependentCommandWhenSupportHasAdmin(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'ADMIN';
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
                return null;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('hasAdmin')->willReturn(true);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
            $modeSupport,
        );
        $adapter = new HelpFormatterContextAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpHidesModeDependentCommandWhenSupportLacksMode(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            public function getName(): string
            {
                return 'ADMIN';
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
                return null;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($cmd));
    }
}
