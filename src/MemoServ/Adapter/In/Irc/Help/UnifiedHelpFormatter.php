<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Help;

use function is_array;
use function sprintf;
use function strtoupper;

/**
 * Renders unified HELP output (header, command list, options, syntax, footer)
 * within MemoServ. The context supplies replies, translations, command groups and visibility.
 */
final readonly class UnifiedHelpFormatter
{
    private const int CMD_PAD = 12;

    private const int SUBS_PAD = 10;

    private const int HEADER_WIDTH = 40;

    public function sendHeader(HelpFormatterContextInterface $context, string $title): void
    {
        $visible = 4 + mb_strlen($title);
        $dashes = str_repeat('─', max(0, self::HEADER_WIDTH - $visible));
        $line = sprintf("\x02\x0312 ● %s \x0F\x0314%s\x03", $title, $dashes);
        $context->replyRaw($line);
    }

    public function showGeneralHelp(HelpFormatterContextInterface $context): void
    {
        /** @var array<string, HelpableCommandInterface> $visibleCommands */
        $visibleCommands = [];
        foreach ($context->getCommandsForGeneralHelp() as $command) {
            if ('HELP' !== $command->getName() && $context->shouldShowCommandInGeneralHelp($command)) {
                $visibleCommands[strtoupper($command->getName())] = $command;
            }
        }

        $this->sendHeader($context, $context->trans('help.header_title'));
        $context->reply('help.intro');
        $context->replyRaw(' ');
        $context->reply('help.general_header');

        $shown = [];
        $generalFooterSent = false;
        $ircopHeaderSent = false;
        foreach ($context->getHelpGroups() as $group) {
            $groupCommands = [];
            foreach ($group['commands'] as $name) {
                $name = strtoupper($name);
                if (isset($visibleCommands[$name]) && !isset($shown[$name])) {
                    $groupCommands[] = $visibleCommands[$name];
                    $shown[$name] = true;
                }
            }
            if ([] === $groupCommands) {
                continue;
            }
            usort($groupCommands, static fn (HelpableCommandInterface $a, HelpableCommandInterface $b): int => $a->getOrder() <=> $b->getOrder());

            if ($group['admin']) {
                if (!$generalFooterSent) {
                    $context->replyRaw(' ');
                    $context->reply('help.general_footer');
                    $generalFooterSent = true;
                }
                if (!$ircopHeaderSent) {
                    $context->replyRaw(' ');
                    $context->reply('help.ircop_header');
                    $ircopHeaderSent = true;
                }
                if ($group['subgroup']) {
                    $context->replyRaw(' ');
                    $context->reply('help.subgroup_header', ['group' => $context->trans($group['group_key'])]);
                }
            } else {
                $context->replyRaw(' ');
                $context->reply('help.group_header', ['group' => $context->trans($group['group_key'])]);
            }

            foreach ($groupCommands as $command) {
                $context->reply('help.command_line', [
                    'command' => str_pad($command->getName(), self::CMD_PAD),
                    'description' => $context->trans($command->getShortDescKey()),
                ]);
            }
        }

        if (!$generalFooterSent) {
            $context->replyRaw(' ');
            $context->reply('help.general_footer');
        }

        // Keep a visible fallback for any future command not yet assigned to a group.
        $ungroupedCommands = [];
        foreach ($visibleCommands as $name => $command) {
            if (!isset($shown[$name])) {
                $ungroupedCommands[] = $command;
            }
        }
        usort($ungroupedCommands, static fn (HelpableCommandInterface $a, HelpableCommandInterface $b): int => $a->getOrder() <=> $b->getOrder());
        foreach ($ungroupedCommands as $command) {
            $context->reply('help.command_line', [
                'command' => str_pad($command->getName(), self::CMD_PAD),
                'description' => $context->trans($command->getShortDescKey()),
            ]);
        }
        // Caller sends help.footer (allows e.g. NickServ to add intro_expiration before it).
    }

    public function showCommandHelp(HelpFormatterContextInterface $context, HelpableCommandInterface $handler): void
    {
        $this->sendHeader($context, 'HELP ' . $handler->getName());

        $rawParams = method_exists($handler, 'getHelpParams') ? $handler->getHelpParams() : [];
        /** @var array<string, mixed> $params */
        $params = is_array($rawParams) ? $rawParams : [];
        $context->reply($handler->getHelpKey(), $params);

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

    /**
     * @param array{name: string, help_key: string, syntax_key: string, options_key?: string} $sub
     */
    public function showSubCommandHelp(HelpFormatterContextInterface $context, string $parentName, array $sub): void
    {
        $this->sendHeader($context, 'HELP ' . $parentName . ' ' . $sub['name']);
        $context->reply($sub['help_key']);
        if (isset($sub['options_key'])) {
            $context->replyRaw(' ');
            $context->reply($sub['options_key']);
        }
        $context->replyRaw(' ');
        $context->reply('help.syntax_label', ['syntax' => $context->trans($sub['syntax_key'])]);
        $context->reply('help.footer');
    }
}
