<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;

/**
 * HELP [command]
 *
 * Without arguments: lists all available commands.
 * With a command name: shows the full help text for that command.
 *
 * The registry is obtained from the context to avoid a circular dependency:
 * NickServCommandRegistry → HelpCommand → NickServCommandRegistry.
 */
final class HelpCommand implements NickServCommandInterface
{
    public function __construct()
    {
    }

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

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if ($sender === null) {
            return;
        }

        if (empty($context->args)) {
            $this->showGeneralHelp($context);
            return;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler   = $context->getRegistry()->find($targetCmd);

        if ($handler === null) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);
            return;
        }

        if ($handler->isOperOnly() && !$sender->isOper()) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);
            return;
        }

        $this->showCommandHelp($context, $handler);
    }

    private function showGeneralHelp(NickServContext $context): void
    {
        $isOper   = $context->sender?->isOper() ?? false;
        $commands = $context->getRegistry()->all();

        $context->reply('help.intro');
        $context->replyRaw(' ');
        $context->reply('help.general_header');

        foreach ($commands as $command) {
            if ($command->getName() === 'HELP') {
                continue;
            }

            if ($command->isOperOnly() && !$isOper) {
                continue;
            }

            $context->reply('help.command_line', [
                'command'     => str_pad($command->getName(), 12),
                'description' => $context->trans($command->getShortDescKey()),
            ]);
        }

        $context->reply('help.general_footer');
    }

    private function showCommandHelp(NickServContext $context, \App\Application\NickServ\Command\NickServCommandInterface $handler): void
    {
        $context->reply('help.command_header', ['command' => $handler->getName()]);
        $context->reply($handler->getHelpKey());

        $subCmds = $handler->getSubCommandHelp();

        if ($subCmds !== []) {
            foreach ($subCmds as $sub) {
                $context->reply('help.subcommand_line', [
                    'command'     => str_pad($sub['name'], 10),
                    'description' => $context->trans($sub['desc_key']),
                ]);
            }
        }

        $context->reply('help.syntax_label', ['syntax' => $context->trans($handler->getSyntaxKey())]);
        $context->reply('help.footer');
    }
}
