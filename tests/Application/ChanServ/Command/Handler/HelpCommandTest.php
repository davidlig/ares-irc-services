<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\HelpCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\SenderView;
use App\Application\Shared\Help\UnifiedHelpFormatter;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    private function createContext(
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ChanServCommandRegistry $registry,
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
        );
    }

    #[Test]
    public function emptyArgsShowsGeneralHelpAndFooter(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class() implements ChanServCommandInterface {
            public function getName(): string { return 'HELP'; }
            public function getAliases(): array { return ['?']; }
            public function getMinArgs(): int { return 0; }
            public function getSyntaxKey(): string { return 'help.syntax'; }
            public function getHelpKey(): string { return 'help.help'; }
            public function getOrder(): int { return 99; }
            public function getShortDescKey(): string { return 'help.short'; }
            public function getSubCommandHelp(): array { return []; }
            public function isOperOnly(): bool { return false; }
            public function getRequiredPermission(): ?string { return null; }
            public function execute(\App\Application\ChanServ\Command\ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), 0);
        $cmd->execute($this->createContext([], $notifier, $translator, $registry));

        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function unknownCommandRepliesHelpUnknown(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class() implements ChanServCommandInterface {
            public function getName(): string { return 'REGISTER'; }
            public function getAliases(): array { return []; }
            public function getMinArgs(): int { return 0; }
            public function getSyntaxKey(): string { return ''; }
            public function getHelpKey(): string { return 'register.help'; }
            public function getOrder(): int { return 0; }
            public function getShortDescKey(): string { return ''; }
            public function getSubCommandHelp(): array { return []; }
            public function isOperOnly(): bool { return false; }
            public function getRequiredPermission(): ?string { return null; }
            public function execute(\App\Application\ChanServ\Command\ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), 0);
        $cmd->execute($this->createContext(['UNKNOWNCMD'], $notifier, $translator, $registry));

        self::assertContains('help.unknown_command', $messages);
    }
}
