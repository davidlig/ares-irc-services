<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;

/**
 * HELP [command [sub-option]].
 *
 * Lists commands (filtered by IRCd mode support) or shows help for a command.
 * Design aligned with NickServ: header with icon, coloured sections, syntax label, footer.
 */
final readonly class HelpCommand implements ChanServCommandInterface
{
    private const int CMD_PAD = 12;

    private const int SUBS_PAD = 10;

    private const int HEADER_WIDTH = 40;

    /** Commands that require specific mode support to show (name => mode letter). */
    private const array MODE_DEPENDENT_COMMANDS = [
        'ADMIN' => 'a',
        'DEADMIN' => 'a',
        'HALFOP' => 'h',
        'DEHALFOP' => 'h',
    ];

    public function getName(): string
    {
        return 'HELP';
    }

    public function getAliases(): array
    {
        return ['?'];
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return 'help.syntax';
    }

    public function getHelpKey(): string
    {
        return 'help.help';
    }

    public function getOrder(): int
    {
        return 99;
    }

    public function getShortDescKey(): string
    {
        return 'help.short';
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

    public function execute(ChanServContext $context): void
    {
        if (empty($context->args)) {
            $this->showGeneralHelp($context);

            return;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler = $context->getRegistry()->find($targetCmd);

        if (null === $handler) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);

            return;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                $this->showSubCommandHelp($context, $handler->getName(), $subCmd);

                return;
            }
        }

        $this->showCommandHelp($context, $handler);
    }

    private function showGeneralHelp(ChanServContext $context): void
    {
        $commands = $context->getRegistry()->all();
        usort($commands, static fn (ChanServCommandInterface $a, ChanServCommandInterface $b): int => $a->getOrder() <=> $b->getOrder());

        $this->sendHeader($context, $context->trans('help.header_title'));
        $context->reply('help.intro');
        $context->replyRaw(' ');
        $context->reply('help.general_header');

        foreach ($commands as $command) {
            if ('HELP' === $command->getName()) {
                continue;
            }

            if ($command->isOperOnly()) {
                continue;
            }

            $name = $command->getName();
            if (isset(self::MODE_DEPENDENT_COMMANDS[$name])) {
                $mode = self::MODE_DEPENDENT_COMMANDS[$name];
                $supported = ['a' => $context->getChannelModeSupport()->hasAdmin(), 'h' => $context->getChannelModeSupport()->hasHalfOp()][$mode] ?? false;
                if (!$supported) {
                    continue;
                }
            }

            $context->reply('help.command_line', [
                'command' => str_pad($name, self::CMD_PAD),
                'description' => $context->trans($command->getShortDescKey()),
            ]);
        }

        $context->replyRaw(' ');
        $context->reply('help.general_footer');
        $context->reply('help.footer');
    }

    private function showCommandHelp(ChanServContext $context, ChanServCommandInterface $handler): void
    {
        $this->sendHeader($context, $handler->getName());
        $context->reply($handler->getHelpKey());

        $subCmds = $handler->getSubCommandHelp();

        if ([] !== $subCmds) {
            $context->replyRaw(' ');
            $context->reply('help.options_header');

            foreach ($subCmds as $sub) {
                $context->reply('help.subcommand_line', [
                    'command' => str_pad($sub['name'], self::SUBS_PAD),
                    'description' => $context->trans($sub['desc_key']),
                ]);
            }

            $context->replyRaw(' ');
            $context->reply('help.set_sub_footer', ['command' => $handler->getName()]);
        }

        $context->replyRaw(' ');
        $context->reply('help.syntax_label', ['syntax' => $context->trans($handler->getSyntaxKey())]);
        $context->reply('help.footer');
    }

    private function showSubCommandHelp(ChanServContext $context, string $parentName, array $sub): void
    {
        $this->sendHeader($context, $parentName . ' ' . $sub['name']);
        $context->reply($sub['help_key']);
        if (isset($sub['options_key'])) {
            $context->replyRaw(' ');
            $context->reply($sub['options_key']);
        }
        $context->replyRaw(' ');
        $context->reply('help.syntax_label', ['syntax' => $context->trans($sub['syntax_key'])]);
        $context->reply('help.footer');
    }

    /**
     * Sends a coloured section header (same style as NickServ).
     */
    private function sendHeader(ChanServContext $context, string $title): void
    {
        $visible = 4 + mb_strlen($title) + 1;
        $dashes = str_repeat('─', max(0, self::HEADER_WIDTH - $visible));
        $line = "\x02\x0307 ℹ " . $title . " \x0F\x0314" . $dashes . "\x03";
        $context->replyRaw($line);
    }

    /** @return array{name: string, desc_key: string, help_key: string, syntax_key: string}|null */
    private function findSubCommand(ChanServCommandInterface $handler, string $name): ?array
    {
        foreach ($handler->getSubCommandHelp() as $sub) {
            if (strtoupper($sub['name']) === $name) {
                return $sub;
            }
        }

        return null;
    }
}
