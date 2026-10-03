<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Help;

use function is_array;
use function strtoupper;

/**
 * Renders unified HELP output (header, command list, options, syntax, footer)
 * within OperServ. The context supplies translations, command groups and visibility; rendering sends no output.
 */
final readonly class UnifiedHelpFormatter
{
    private const int CMD_PAD = 12;

    private const int SUBS_PAD = 10;

    private const int HEADER_WIDTH = 40;

    public function renderHeader(HelpFormatterContextInterface $context, string $title): string
    {
        $visible = 3 + mb_strlen($title);
        $dashes = str_repeat('─', max(0, self::HEADER_WIDTH - $visible));

        return $context->trans('help.header', [
            'title' => $title,
            'separator' => $dashes,
        ]);
    }

    /** @return list<string> */
    public function renderGeneralHelp(HelpFormatterContextInterface $context): array
    {
        /** @var array<string, HelpableCommandInterface> $visibleCommands */
        $visibleCommands = [];
        foreach ($context->getCommandsForGeneralHelp() as $command) {
            if ('HELP' !== $command->getName() && $context->shouldShowCommandInGeneralHelp($command)) {
                $visibleCommands[strtoupper($command->getName())] = $command;
            }
        }
        if ($context->hasIrcopAccess()) {
            foreach ($context->getIrcopCommands() as $command) {
                if ('HELP' !== $command->getName()) {
                    $visibleCommands[strtoupper($command->getName())] = $command;
                }
            }
        }

        $lines = [$this->renderHeader($context, $context->trans('help.header_title'))];
        $lines[] = $context->trans('help.intro');
        $lines[] = ' ';
        $lines[] = $context->trans('help.general_header');

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
                    $lines[] = ' ';
                    $lines[] = $context->trans('help.general_footer');
                    $generalFooterSent = true;
                }
                if (!$ircopHeaderSent) {
                    $lines[] = ' ';
                    $lines[] = $context->trans('help.ircop_header');
                    $ircopHeaderSent = true;
                }
                if ($group['subgroup']) {
                    $lines[] = ' ';
                    $lines[] = $context->trans('help.subgroup_header', ['group' => $context->trans($group['group_key'])]);
                }
            } else {
                $lines[] = ' ';
                $lines[] = $context->trans('help.group_header', ['group' => $context->trans($group['group_key'])]);
            }

            foreach ($groupCommands as $command) {
                $lines[] = $context->trans('help.command_line', [
                    'command' => str_pad($command->getName(), self::CMD_PAD),
                    'description' => $context->trans($command->getShortDescKey()),
                ]);
            }
        }

        if (!$generalFooterSent) {
            $lines[] = ' ';
            $lines[] = $context->trans('help.general_footer');
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
            $lines[] = $context->trans('help.command_line', [
                'command' => str_pad($command->getName(), self::CMD_PAD),
                'description' => $context->trans($command->getShortDescKey()),
            ]);
        }
        // The caller appends the final help.footer before sending the complete response.

        return $lines;
    }

    /** @return list<string> */
    public function renderCommandHelp(HelpFormatterContextInterface $context, HelpableCommandInterface $handler): array
    {
        $lines = [$this->renderHeader($context, 'HELP ' . $handler->getName())];

        $rawParams = method_exists($handler, 'getHelpParams') ? $handler->getHelpParams() : [];
        /** @var array<string, mixed> $params */
        $params = is_array($rawParams) ? $rawParams : [];
        $lines[] = $context->trans($handler->getHelpKey(), $params);

        $subCmds = $handler->getSubCommandHelp();

        if ([] !== $subCmds) {
            $lines[] = ' ';
            $lines[] = $context->trans('help.options_header');

            foreach ($subCmds as $sub) {
                $lines[] = $context->trans('help.subcommand_line', [
                    'command' => str_pad($sub['name'], self::SUBS_PAD),
                    'description' => $context->trans($sub['desc_key']),
                ]);
            }

            $lines[] = ' ';
            $lines[] = $context->trans('help.set_sub_footer', ['command' => $handler->getName()]);
        }

        $lines[] = ' ';
        $lines[] = $context->trans('help.syntax_label', ['syntax' => $context->trans($handler->getSyntaxKey())]);
        $lines[] = $context->trans('help.footer');

        return $lines;
    }

    /**
     * @param array{name: string, help_key: string, syntax_key: string, options_key?: string} $sub
     *
     * @return list<string>
     */
    public function renderSubCommandHelp(HelpFormatterContextInterface $context, string $parentName, array $sub): array
    {
        $lines = [$this->renderHeader($context, 'HELP ' . $parentName . ' ' . $sub['name'])];
        $lines[] = $context->trans($sub['help_key']);
        if (isset($sub['options_key'])) {
            $lines[] = ' ';
            $lines[] = $context->trans($sub['options_key']);
        }
        $lines[] = ' ';
        $lines[] = $context->trans('help.syntax_label', ['syntax' => $context->trans($sub['syntax_key'])]);
        $lines[] = $context->trans('help.footer');

        return $lines;
    }
}
