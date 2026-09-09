<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServHelpFormatterContextAdapter;

use function array_find;
use function strtoupper;

final readonly class HelpCommand implements OperServCommandInterface
{
    public function __construct(private UnifiedHelpFormatter $formatter) {}

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

    public function execute(OperServContext $context): void
    {
        if (null !== $context->sender && !$context->isAuthorized(null)) {
            $context->reply('error.oper_only');

            return;
        }

        $formatterContext = new OperServHelpFormatterContextAdapter($context);
        if (empty($context->args)) {
            $this->formatter->showGeneralHelp($formatterContext);
            $context->reply('help.footer');

            return;
        }

        $commandName = strtoupper($context->args[0]);
        $command = $context->commandRegistry()->find($commandName);
        if (null === $command || !$this->canView($context, $command)) {
            $context->reply('help.unknown_command', ['command' => $commandName]);

            return;
        }

        $subcommand = isset($context->args[1]) && [] !== $command->getSubCommandHelp()
            ? array_find($command->getSubCommandHelp(), static fn (array $sub): bool => strtoupper($sub['name']) === strtoupper($context->args[1]))
            : null;
        if (null !== $subcommand) {
            $this->formatter->showSubCommandHelp($formatterContext, $command->getName(), $subcommand);

            return;
        }

        $this->formatter->showCommandHelp($formatterContext, $command);
    }

    private function canView(OperServContext $context, OperServCommandInterface $command): bool
    {
        $permission = $command->getRequiredPermission();

        return null !== $permission
            ? $context->isAuthorized($permission)
            : (!$command->isOperOnly() || $context->isAuthorized(null));
    }
}
