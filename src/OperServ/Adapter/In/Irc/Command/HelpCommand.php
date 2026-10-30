<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServHelpFormatterContextAdapter;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

use function array_find;
use function implode;
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
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('help.syntax', [
            new Argument('command', optional: true),
            new Argument('subcommand', optional: true),
        ]);
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'help.help';
    }

    public function getExampleKey(): string
    {
        return 'help.example';
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
            $context->replyError('error.oper_only');

            return;
        }

        $formatterContext = new OperServHelpFormatterContextAdapter($context);
        if (empty($context->args)) {
            $lines = $this->formatter->renderGeneralHelp($formatterContext);
            $lines[] = $formatterContext->trans('help.footer');
            $context->replyRaw(implode("\n", $lines));

            return;
        }

        $commandName = strtoupper($context->args[0]);
        $command = $context->commandRegistry()->find($commandName);
        if (null === $command || !$this->canView($context, $command)) {
            $context->replyError('unknown_command', ['command' => $context->commandFragment($commandName)]);

            return;
        }

        $subcommand = isset($context->args[1]) && [] !== $command->getSubCommandHelp()
            ? array_find($command->getSubCommandHelp(), static fn (array $sub): bool => strtoupper($sub['name']) === strtoupper($context->args[1]))
            : null;
        if (null !== $subcommand) {
            $context->replyRaw(implode("\n", $this->formatter->renderSubCommandHelp($formatterContext, $command->getName(), $subcommand)));

            return;
        }

        if (isset($context->args[1]) && [] !== $command->getSubCommandHelp()) {
            $context->replyError('unknown_help_option', [
                'command' => $context->commandFragment($command->getName()),
                'option' => $context->commandFragment(strtoupper($context->args[1])),
            ]);

            return;
        }

        $context->replyRaw(implode("\n", $this->formatter->renderCommandHelp($formatterContext, $command)));
    }

    private function canView(OperServContext $context, OperServCommandInterface $command): bool
    {
        $permission = $command->getRequiredPermission();

        return null !== $permission
            ? $context->isAuthorized($permission)
            : (!$command->isOperOnly() || $context->isAuthorized(null));
    }
}
