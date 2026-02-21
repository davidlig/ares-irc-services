<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;

/**
 * HELP [command]
 *
 * Without arguments: lists all available commands.
 * With a command name: shows the full help text for that command.
 */
final class HelpCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly NickServCommandRegistry $registry,
    ) {
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
        $handler   = $this->registry->find($targetCmd);

        if ($handler === null) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);
            return;
        }

        // Skip oper-only commands for regular users
        if ($handler->isOperOnly() && !$sender->isOper()) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);
            return;
        }

        $context->reply('help.command_header', ['command' => $handler->getName()]);
        $context->reply($handler->getHelpKey());
        $context->reply('help.syntax_label', ['syntax' => $context->trans($handler->getSyntaxKey())]);
        $context->reply('help.footer');
    }

    private function showGeneralHelp(NickServContext $context): void
    {
        $isOper   = $context->sender?->isOper() ?? false;
        $commands = $this->registry->all();

        $context->reply('help.general_header');

        foreach ($commands as $command) {
            if ($command->isOperOnly() && !$isOper) {
                continue;
            }
            $context->reply('help.command_line', [
                'command' => str_pad($command->getName(), 12),
                'syntax'  => $context->trans($command->getSyntaxKey()),
            ]);
        }

        $context->reply('help.general_footer');
    }
}
