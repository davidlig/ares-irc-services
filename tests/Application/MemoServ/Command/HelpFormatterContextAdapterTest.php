<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\MemoServ\Command\HelpFormatterContextAdapter;
use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    #[Test]
    public function replyDelegatesToContext(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Translated text');
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            new SenderView('UID1', 'N', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $adapter->reply('help.header_title');

        self::assertCount(1, $sent);
        self::assertSame('Translated text', $sent[0]['msg']);
    }

    #[Test]
    public function replyRawDelegatesToContext(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$sent): void {
            $sent[] = $msg;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            new SenderView('UID1', 'N', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $adapter->replyRaw('Raw line');

        self::assertCount(1, $sent);
        self::assertSame('Raw line', $sent[0]);
    }

    #[Test]
    public function transDelegatesToContextAndReturnsString(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = new MemoServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $result = $adapter->trans('help.syntax');

        self::assertSame('help.syntax', $result);
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsRegistryAll(): void
    {
        $handler = $this->createCommandStub('SEND');
        $registry = new MemoServCommandRegistry([$handler]);
        $context = new MemoServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $commands = $adapter->getCommandsForGeneralHelp();

        self::assertCount(1, [...$commands]);
        $first = [...$commands][0];
        self::assertSame($handler, $first);
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueWhenNotOperOnly(): void
    {
        $command = $this->createCommandStub('LIST', false);
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseWhenOperOnly(): void
    {
        $command = $this->createCommandStub('ADMIN', true);
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function getIrcopCommandsReturnsEmpty(): void
    {
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertSame([], iterator_to_array($adapter->getIrcopCommands()));
    }

    #[Test]
    public function hasIrcopAccessReturnsFalse(): void
    {
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertFalse($adapter->hasIrcopAccess());
    }

    private function createMinimalContext(): MemoServContext
    {
        return new MemoServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
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

    private function createCommandStub(string $name, bool $operOnly = false): MemoServCommandInterface
    {
        return new class($name, $operOnly) implements MemoServCommandInterface {
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

            public function execute(MemoServContext $context): void
            {
            }
        };
    }
}
