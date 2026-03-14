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
        $cmd = new class {
            public function getName(): string
            {
                return 'HELP';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short';
            }

            public function getSyntaxKey(): string
            {
                return 'syntax';
            }

            public function getHelpKey(): string
            {
                return 'help';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }
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
    public function showGeneralHelpOmitsHelpCommandFromCommandList(): void
    {
        $helpCmd = new class {
            public function getName(): string
            {
                return 'HELP';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short.help';
            }
        };
        $fooCmd = new class {
            public function getName(): string
            {
                return 'FOO';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'short.foo';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->method('getCommandsForGeneralHelp')->willReturn([$helpCmd, $fooCmd]);
        $context->method('shouldShowCommandInGeneralHelp')->willReturn(true);
        $context->method('trans')->willReturn('');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $commandLineCalls = 0;
        $context->method('reply')->willReturnCallback(
            static function (string $key) use (&$commandLineCalls): void {
                if ('help.command_line' === $key) {
                    ++$commandLineCalls;
                }
            }
        );

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);

        self::assertSame(1, $commandLineCalls, 'HELP command should be omitted; only FOO should produce one command_line');
    }

    #[Test]
    public function showGeneralHelpSkipsCommandWhenShouldShowCommandInGeneralHelpReturnsFalse(): void
    {
        $hiddenCmd = new class {
            public function getName(): string
            {
                return 'HIDDEN';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short.hidden';
            }
        };
        $visibleCmd = new class {
            public function getName(): string
            {
                return 'VISIBLE';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'short.visible';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->method('getCommandsForGeneralHelp')->willReturn([$hiddenCmd, $visibleCmd]);
        $context->method('shouldShowCommandInGeneralHelp')->willReturnCallback(
            static fn (object $cmd): bool => 'VISIBLE' === $cmd->getName()
        );
        $context->method('trans')->willReturn('');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $commandLineCalls = 0;
        $context->method('reply')->willReturnCallback(
            static function (string $key) use (&$commandLineCalls): void {
                if ('help.command_line' === $key) {
                    ++$commandLineCalls;
                }
            }
        );

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);

        self::assertSame(1, $commandLineCalls, 'Only VISIBLE should be shown when shouldShowCommandInGeneralHelp is false for HIDDEN');
    }

    #[Test]
    public function showCommandHelpCallsReplyWithHandlerData(): void
    {
        $handler = new class {
            public function getName(): string
            {
                return 'REGISTER';
            }

            public function getHelpKey(): string
            {
                return 'help.register';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function getSyntaxKey(): string
            {
                return 'syntax.register';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::exactly(3))->method('reply'); // help.register, help.syntax_label, help.footer
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->method('trans')->willReturn('Syntax here');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showCommandHelp($context, $handler);
    }

    #[Test]
    public function showCommandHelpWithSubCommandsShowsOptionsBlock(): void
    {
        $handler = new class {
            public function getName(): string
            {
                return 'SET';
            }

            public function getHelpKey(): string
            {
                return 'help.set';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    ['name' => 'FOUNDER', 'desc_key' => 'help.set.founder'],
                ];
            }

            public function getSyntaxKey(): string
            {
                return 'syntax.set';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('reply');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->method('trans')->willReturn('x');

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
