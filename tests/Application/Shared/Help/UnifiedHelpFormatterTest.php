<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared\Help;

use App\Application\Shared\Help\HelpFormatterContextInterface;
use App\Application\Shared\Help\UnifiedHelpFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnifiedHelpFormatter::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    #[Test]
    public function sendHeaderCallsReplyRawWithFormattedTitle(): void
    {
        $formatter = new UnifiedHelpFormatter();
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::once())->method('replyRaw')
            ->with(self::callback(static fn (string $s): bool => str_contains($s, 'ℹ') && str_contains($s, 'My Title')));

        $formatter->sendHeader($context, 'My Title');
    }

    #[Test]
    public function showGeneralHelpCallsReplyAndReplyRaw(): void
    {
        $cmd = new class() {
            public function getName(): string { return 'HELP'; }
            public function getOrder(): int { return 0; }
            public function getShortDescKey(): string { return 'short'; }
            public function getSyntaxKey(): string { return 'syntax'; }
            public function getHelpKey(): string { return 'help'; }
            public function getSubCommandHelp(): array { return []; }
            public function isOperOnly(): bool { return false; }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->method('getCommandsForGeneralHelp')->willReturn([$cmd]);
        $context->method('shouldShowCommandInGeneralHelp')->willReturn(true);
        $context->expects(self::atLeastOnce())->method('reply');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->method('trans')->willReturn('');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);
    }

    #[Test]
    public function showCommandHelpCallsReplyWithHandlerData(): void
    {
        $handler = new class() {
            public function getName(): string { return 'REGISTER'; }
            public function getHelpKey(): string { return 'help.register'; }
            public function getSubCommandHelp(): array { return []; }
            public function getSyntaxKey(): string { return 'syntax.register'; }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::exactly(3))->method('reply'); // help.register, help.syntax_label, help.footer
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->method('trans')->willReturn('Syntax here');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showCommandHelp($context, $handler);
    }

    #[Test]
    public function showSubCommandHelpCallsReplyWithSubData(): void
    {
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::exactly(3))->method('reply'); // help_key, help.syntax_label, help.footer
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->method('trans')->willReturn('syntax');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showSubCommandHelp($context, 'ACCESS', [
            'name' => 'ADD',
            'help_key' => 'help.access.add',
            'syntax_key' => 'syntax.access.add',
        ]);
    }

    #[Test]
    public function showSubCommandHelpIncludesOptionsWhenPresent(): void
    {
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::exactly(4))->method('reply'); // help_key, options_key, help.syntax_label, help.footer
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->method('trans')->willReturn('x');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showSubCommandHelp($context, 'ACCESS', [
            'name' => 'ADD',
            'help_key' => 'help.access.add',
            'syntax_key' => 'syntax.access.add',
            'options_key' => 'options.access.add',
        ]);
    }
}
