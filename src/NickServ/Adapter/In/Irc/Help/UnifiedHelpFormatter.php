<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Help;

use function array_map;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function ltrim;
use function method_exists;
use function str_contains;
use function str_pad;
use function str_replace;
use function strtoupper;
use function usort;

/**
 * Renders unified HELP output (header, command list, options, syntax, footer)
 * within NickServ. The context supplies translations, command groups and visibility.
 */
final readonly class UnifiedHelpFormatter
{
    private const int CMD_PAD = 12;

    private const int SUBS_PAD = 10;

    public function renderHeader(HelpFormatterContextInterface $context, string $title): string
    {
        return $context->trans('help.header', ['title' => $title]);
    }

    public function renderFooter(HelpFormatterContextInterface $context): string
    {
        return $context->trans('help.footer');
    }

    /** @return list<string> */
    public function renderGeneralHelp(HelpFormatterContextInterface $context, ?string $preIrcopNotice = null): array
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

        $lines = [$this->renderHeader($context, $context->trans('help.header_title')), ' '];
        $lines[] = $context->trans('help.intro');
        $lines[] = ' ';
        $lines[] = ' ';
        $lines[] = $context->trans('help.general_header');

        $shown = [];
        $ircopHeaderSent = false;
        $preIrcopNoticeSent = false;
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
                if (!$ircopHeaderSent) {
                    if (null !== $preIrcopNotice) {
                        $lines[] = ' ';
                        $lines[] = $preIrcopNotice;
                        $preIrcopNoticeSent = true;
                    }
                    $lines[] = ' ';
                    $lines[] = $context->trans('help.ircop_header');
                    $ircopHeaderSent = true;
                }
                if ($group['subgroup']) {
                    $lines[] = ' ';
                    $lines[] = $context->trans('help.subgroup_header', [
                        'group' => $context->trans($group['group_key']),
                    ]);
                }
            } else {
                $lines[] = ' ';
                $lines[] = $context->trans('help.group_header', [
                    'group' => $context->trans($group['group_key']),
                ]);
            }

            foreach ($groupCommands as $command) {
                $lines[] = $context->trans('help.command_line', [
                    'marker' => $context->trans('help.navigation_marker'),
                    'command' => str_pad($command->getName(), self::CMD_PAD),
                    'description' => $context->trans($command->getShortDescKey()),
                ]);
            }
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
                'marker' => $context->trans('help.navigation_marker'),
                'command' => str_pad($command->getName(), self::CMD_PAD),
                'description' => $context->trans($command->getShortDescKey()),
            ]);
        }
        if (null !== $preIrcopNotice && !$preIrcopNoticeSent) {
            $lines[] = ' ';
            $lines[] = $preIrcopNotice;
        }
        $lines[] = ' ';
        $lines[] = ' ';
        $lines[] = $context->trans('help.general_footer', [
            'marker' => $context->trans('help.info_marker'),
            'syntax' => $context->trans('help.general_syntax'),
        ]);
        // Caller appends help.footer after the final general-help hint.

        return $lines;
    }

    /** @return list<string> */
    public function renderCommandHelp(HelpFormatterContextInterface $context, HelpableCommandInterface $handler): array
    {
        $lines = [$this->renderHeader($context, 'HELP ' . $handler->getName()), ' '];

        $rawParams = method_exists($handler, 'getHelpParams') ? $handler->getHelpParams() : [];
        /** @var array<string, mixed> $params */
        $params = is_array($rawParams) ? $rawParams : [];
        if (is_int($params['duration_seconds'] ?? null)) {
            $params['duration'] = $context->formatDuration($params['duration_seconds']);
            unset($params['duration_seconds']);
        }
        $lines[] = $context->trans($handler->getHelpKey(), $params);

        $subCmds = $handler->getSubCommandHelp();
        if ([] !== $subCmds) {
            $lines[] = ' ';
            $lines[] = $context->trans('help.options_header');

            foreach ($subCmds as $sub) {
                $lines[] = $context->trans('help.subcommand_line', [
                    'marker' => $context->trans('help.navigation_marker'),
                    'command' => str_pad($sub['name'], self::SUBS_PAD),
                    'description' => $context->trans($sub['desc_key']),
                ]);
            }

            $lines[] = ' ';
            $lines[] = $context->trans('help.set_sub_footer', [
                'marker' => $context->trans('help.info_marker'),
                'syntax' => $context->trans('help.set_sub_syntax', [
                    'command' => $handler->getName(),
                ]),
            ]);
        }

        $lines[] = ' ';
        $lines[] = $context->trans('help.syntax_label', [
            'syntax' => $context->trans($handler->getSyntaxKey()),
        ]);
        $lines = [...$lines, ...$this->renderExamples($context, $handler->getExampleKey())];

        $lines[] = $this->renderFooter($context);

        return $lines;
    }

    /**
     * @param array{name: string, help_key: string, syntax_key: string, example_key: string, options_key?: string} $sub
     *
     * @return list<string>
     */
    public function renderSubCommandHelp(HelpFormatterContextInterface $context, string $parentName, array $sub): array
    {
        $lines = [$this->renderHeader($context, 'HELP ' . $parentName . ' ' . $sub['name']), ' '];
        $lines[] = $this->preserveParagraphRows($context->trans($sub['help_key']));
        $lines[] = ' ';
        $lines[] = $context->trans('help.syntax_label', [
            'syntax' => $context->trans($sub['syntax_key']),
        ]);
        $lines = [...$lines, ...$this->renderExamples($context, $sub['example_key'])];
        if (isset($sub['options_key'])) {
            $lines[] = ' ';
            $lines[] = $this->preserveParagraphRows($context->trans($sub['options_key']));
        }
        $lines[] = $this->renderFooter($context);

        return $lines;
    }

    /** @return list<string> */
    public function renderExamples(HelpFormatterContextInterface $context, string $exampleKey): array
    {
        $headerKey = 'help.examples_header';
        $header = $context->trans($headerKey);
        $example = $context->trans($exampleKey);
        if ($headerKey === $header || $exampleKey === $example) {
            return [];
        }

        $lines = [' ', $header, $this->addBotMessagePrefix($context, $example)];
        for ($index = 0;; ++$index) {
            $additionalExampleKey = $exampleKey . 's.' . $index;
            $additionalExample = $context->trans($additionalExampleKey);
            if ($additionalExampleKey === $additionalExample) {
                break;
            }

            $lines[] = $this->addBotMessagePrefix($context, $additionalExample);
        }

        return $lines;
    }

    private function preserveParagraphRows(string $message): string
    {
        return implode("\n", array_map(
            static fn (string $line): string => '' === $line ? ' ' : $line,
            explode("\n", $message),
        ));
    }

    private function addBotMessagePrefix(HelpFormatterContextInterface $context, string $example): string
    {
        $prefixPlaceholder = '%command_prefix%';
        if (!str_contains($example, $prefixPlaceholder)) {
            return $example;
        }

        $example = str_replace($prefixPlaceholder, '/msg ' . $context->getBotName() . ' ', $example);

        return '  ' . ltrim($example, " \t");
    }
}
