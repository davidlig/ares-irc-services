<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared\Help;

use App\Application\Shared\Help\HelpFormatterContextInterface;
use App\Application\Shared\Help\UnifiedHelpFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

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
    public function sendHeaderUsesCorrectDashCountForHeaderWidth(): void
    {
        $formatter = new UnifiedHelpFormatter();
        $captured = null;
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::once())->method('replyRaw')
            ->with(self::callback(static function (string $s) use (&$captured): bool {
                $captured = $s;

                return true;
            }));

        $formatter->sendHeader($context, 'AB');
        self::assertNotNull($captured);
        // visible = 4 + mb_strlen('AB') + 1 = 7, HEADER_WIDTH = 40 → 33 dashes
        $dashCount = substr_count($captured, '─');
        self::assertSame(33, $dashCount, 'Header should have 33 dashes for title "AB"');
    }

    #[Test]
    public function sendHeaderUsesMbStrlenForMultibyteTitle(): void
    {
        $formatter = new UnifiedHelpFormatter();
        $captured = null;
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::once())->method('replyRaw')
            ->with(self::callback(static function (string $s) use (&$captured): bool {
                $captured = $s;

                return true;
            }));

        $formatter->sendHeader($context, 'Título');
        self::assertNotNull($captured);
        self::assertStringContainsString('Título', $captured);
        // visible = 4 + 6 + 1 = 11 (Título = 6 mb chars), 40 - 11 = 29 dashes
        $dashCount = substr_count($captured, '─');
        self::assertSame(29, $dashCount);
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
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([$cmd]);
        $context->expects(self::never())->method('shouldShowCommandInGeneralHelp');
        $context->expects(self::atLeastOnce())->method('reply');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');

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
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([$helpCmd, $fooCmd]);
        $context->expects(self::atLeastOnce())->method('shouldShowCommandInGeneralHelp')->willReturn(true);
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $commandLineCalls = 0;
        $context->expects(self::atLeastOnce())->method('reply')->willReturnCallback(
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
    public function showGeneralHelpWithEmptyCommandListStillSendsHeaderAndFooter(): void
    {
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([]);
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');
        $replyRawCalls = [];
        $context->expects(self::atLeastOnce())->method('replyRaw')->willReturnCallback(
            static function (string $s) use (&$replyRawCalls): void {
                $replyRawCalls[] = $s;
            }
        );
        $replyKeys = [];
        $context->expects(self::atLeastOnce())->method('reply')->willReturnCallback(
            static function (string $key) use (&$replyKeys): void {
                $replyKeys[] = $key;
            }
        );

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);

        self::assertNotEmpty(array_filter($replyRawCalls, static fn (string $s): bool => str_contains($s, 'ℹ')));
        self::assertContains('help.intro', $replyKeys);
        self::assertContains('help.general_header', $replyKeys);
        self::assertContains('help.general_footer', $replyKeys);
        self::assertCount(0, array_filter($replyKeys, static fn (string $k): bool => 'help.command_line' === $k));
    }

    #[Test]
    public function showGeneralHelpSortsCommandsByOrder(): void
    {
        $second = new class {
            public function getName(): string
            {
                return 'SECOND';
            }

            public function getOrder(): int
            {
                return 10;
            }

            public function getShortDescKey(): string
            {
                return 'short.second';
            }
        };
        $first = new class {
            public function getName(): string
            {
                return 'FIRST';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'short.first';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([$second, $first]);
        $context->expects(self::atLeastOnce())->method('shouldShowCommandInGeneralHelp')->willReturn(true);
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $commandLineParams = [];
        $context->expects(self::atLeastOnce())->method('reply')->willReturnCallback(
            static function (string $key, array $params = []) use (&$commandLineParams): void {
                if ('help.command_line' === $key) {
                    $commandLineParams[] = $params['command'] ?? null;
                }
            }
        );

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);

        self::assertCount(2, $commandLineParams);
        self::assertStringContainsString('FIRST', $commandLineParams[0]);
        self::assertStringContainsString('SECOND', $commandLineParams[1]);
    }

    #[Test]
    public function showGeneralHelpPadsCommandNameToCmdPad(): void
    {
        $cmd = new class {
            public function getName(): string
            {
                return 'X';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'short.x';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([$cmd]);
        $context->expects(self::atLeastOnce())->method('shouldShowCommandInGeneralHelp')->willReturn(true);
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $capturedCommand = null;
        $context->expects(self::atLeastOnce())->method('reply')->willReturnCallback(
            static function (string $key, array $params = []) use (&$capturedCommand): void {
                if ('help.command_line' === $key) {
                    $capturedCommand = $params['command'] ?? null;
                }
            }
        );

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);

        self::assertSame(12, strlen($capturedCommand ?? ''));
        self::assertStringStartsWith('X', $capturedCommand ?? '');
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
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([$hiddenCmd, $visibleCmd]);
        $context->expects(self::atLeastOnce())->method('shouldShowCommandInGeneralHelp')->willReturnCallback(
            static fn (object $cmd): bool => 'VISIBLE' === $cmd->getName()
        );
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $commandLineCalls = 0;
        $context->expects(self::atLeastOnce())->method('reply')->willReturnCallback(
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
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('Syntax here');

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
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('x');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showCommandHelp($context, $handler);
    }

    #[Test]
    public function showCommandHelpPadsSubCommandNameToSubsPad(): void
    {
        $handler = new class {
            public function getName(): string
            {
                return 'ACCESS';
            }

            public function getHelpKey(): string
            {
                return 'help.access';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    ['name' => 'ADD', 'desc_key' => 'help.access.add'],
                ];
            }

            public function getSyntaxKey(): string
            {
                return 'syntax.access';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('x');
        $subCommandLineParams = [];
        $context->expects(self::atLeastOnce())->method('reply')->willReturnCallback(
            static function (string $key, array $params = []) use (&$subCommandLineParams): void {
                if ('help.subcommand_line' === $key) {
                    $subCommandLineParams[] = $params['command'] ?? null;
                }
            }
        );
        $context->expects(self::atLeastOnce())->method('replyRaw');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showCommandHelp($context, $handler);

        self::assertCount(1, $subCommandLineParams);
        self::assertSame(10, strlen($subCommandLineParams[0] ?? ''));
        self::assertStringStartsWith('ADD', $subCommandLineParams[0] ?? '');
    }

    #[Test]
    public function showSubCommandHelpCallsReplyWithSubData(): void
    {
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::exactly(3))->method('reply'); // help_key, help.syntax_label, help.footer
        $context->expects(self::atLeastOnce())->method('replyRaw');
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('syntax');

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
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('x');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showSubCommandHelp($context, 'ACCESS', [
            'name' => 'ADD',
            'help_key' => 'help.access.add',
            'syntax_key' => 'syntax.access.add',
            'options_key' => 'options.access.add',
        ]);
    }

    #[Test]
    public function showGeneralHelpShowsIrcopCommandsWhenUserHasIrcopAccess(): void
    {
        $cmd = new class {
            public function getName(): string
            {
                return 'USERIP';
            }

            public function getOrder(): int
            {
                return 60;
            }

            public function getShortDescKey(): string
            {
                return 'userip.short';
            }
        };
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([]);
        $context->expects(self::atLeastOnce())->method('hasIrcopAccess')->willReturn(true);
        $context->expects(self::atLeastOnce())->method('getIrcopCommands')->willReturn([$cmd]);
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('USERIP');
        $context->expects(self::exactly(5))->method('reply');
        $context->expects(self::exactly(4))->method('replyRaw');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);
    }

    #[Test]
    public function showGeneralHelpWithoutIrcopAccessDoesNotShowSection(): void
    {
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([]);
        $context->expects(self::atLeastOnce())->method('hasIrcopAccess')->willReturn(false);
        $context->expects(self::never())->method('getIrcopCommands');
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');
        $context->expects(self::exactly(3))->method('reply');
        $context->expects(self::exactly(3))->method('replyRaw');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);
    }

    #[Test]
    public function showGeneralHelpWithIrcopAccessButNoCommandsDoesNotShowSection(): void
    {
        $context = $this->createMock(HelpFormatterContextInterface::class);
        $context->expects(self::atLeastOnce())->method('getCommandsForGeneralHelp')->willReturn([]);
        $context->expects(self::atLeastOnce())->method('hasIrcopAccess')->willReturn(true);
        $context->expects(self::atLeastOnce())->method('getIrcopCommands')->willReturn([]);
        $context->expects(self::atLeastOnce())->method('trans')->willReturn('');
        $context->expects(self::exactly(3))->method('reply');
        $context->expects(self::exactly(3))->method('replyRaw');

        $formatter = new UnifiedHelpFormatter();
        $formatter->showGeneralHelp($context);
    }
}
