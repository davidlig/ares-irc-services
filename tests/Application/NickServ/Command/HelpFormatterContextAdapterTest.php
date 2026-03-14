<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command;

use App\Application\NickServ\Command\HelpFormatterContextAdapter;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
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
        );
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
        $adapter = new HelpFormatterContextAdapter($context);

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
        $adapter = new HelpFormatterContextAdapter($context);

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
        $adapter = new HelpFormatterContextAdapter($context);

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
        $adapter = new HelpFormatterContextAdapter($context);

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
        $adapter = new HelpFormatterContextAdapter($context);

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
        $adapter = new HelpFormatterContextAdapter($context);

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
        $adapter = new HelpFormatterContextAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($command));
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

            public function execute(NickServContext $context): void
            {
            }
        };
    }
}
