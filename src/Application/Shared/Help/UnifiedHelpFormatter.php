<?php

declare(strict_types=1);

namespace App\Application\Shared\Help;

use function sprintf;

/**
 * Renders unified HELP output (header, command list, options, syntax, footer)
 * for both NickServ and ChanServ. Uses HelpFormatterContextInterface so each
 * service provides its own context (reply, trans, command list, visibility filter).
 */
final readonly class UnifiedHelpFormatter
{
    private const int CMD_PAD = 12;

    private const int SUBS_PAD = 10;

    private const int HEADER_WIDTH = 40;

    public function sendHeader(HelpFormatterContextInterface $context, string $title): void
    {
        $visible = 4 + mb_strlen($title) + 1;
        $dashes = str_repeat('─', max(0, self::HEADER_WIDTH - $visible));
        $line = sprintf("\x02\x0307 ℹ %s \x0F\x0314%s\x03", $title, $dashes);
        $context->replyRaw($line);
    }

    public function showGeneralHelp(HelpFormatterContextInterface $context): void
    {
        $commands = iterator_to_array($context->getCommandsForGeneralHelp());
        usort($commands, static fn (object $a, object $b): int => $a->getOrder() <=> $b->getOrder());

        $this->sendHeader($context, $context->trans('help.header_title'));
        $context->reply('help.intro');
        $context->replyRaw(' ');
        $context->reply('help.general_header');

        foreach ($commands as $command) {
            if ('HELP' === $command->getName()) {
                continue;
            }

            if (!$context->shouldShowCommandInGeneralHelp($command)) {
                continue;
            }

            $context->reply('help.command_line', [
                'command' => str_pad($command->getName(), self::CMD_PAD),
                'description' => $context->trans($command->getShortDescKey()),
            ]);
        }

        $context->replyRaw(' ');
        $context->reply('help.general_footer');
        // Caller sends help.footer (allows e.g. NickServ to add intro_expiration before it).
    }

    public function showCommandHelp(HelpFormatterContextInterface $context, object $handler): void
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

    /**
     * @param array{name: string, help_key: string, syntax_key: string, options_key?: string} $sub
     */
    public function showSubCommandHelp(HelpFormatterContextInterface $context, string $parentName, array $sub): void
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
}
