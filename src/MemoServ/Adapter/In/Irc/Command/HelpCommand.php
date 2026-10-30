<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\MemoServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

use function array_find;
use function implode;
use function strtoupper;

/**
 * HELP [command [sub-option]].
 * Design aligned with NickServ/ChanServ via UnifiedHelpFormatter.
 */
final readonly class HelpCommand implements MemoServCommandInterface
{
    public function __construct(
        private UnifiedHelpFormatter $formatter,
    ) {}

    public function getName(): string
    {
        return 'HELP';
    }

    public function getAliases(): array
    {
        return ['?'];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('help.syntax', [new Argument('command', optional: true), new Argument('option', optional: true)]);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
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

    public function execute(MemoServContext $context): null
    {
        if (empty($context->args)) {
            $adapter = new HelpFormatterContextAdapter($context);
            $lines = $this->formatter->renderGeneralHelp($adapter);
            $lines[] = $adapter->trans('help.footer');
            $context->replyRaw(implode("\n", $lines));

            return null;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler = $context->getRegistry()->find($targetCmd);

        if (null === $handler) {
            $context->replyError('unknown_command', ['command' => $context->commandFragment($targetCmd)]);

            return null;
        }

        $adapter = new HelpFormatterContextAdapter($context);
        if (!$adapter->canViewCommandInHelp($handler)) {
            $context->replyError('unknown_command', ['command' => $context->commandFragment($targetCmd)]);

            return null;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                $context->replyRaw(implode("\n", $this->formatter->renderSubCommandHelp($adapter, $handler->getName(), $subCmd)));

                return null;
            }

            $context->replyError('unknown_help_option', [
                'command' => $context->commandFragment($handler->getName()),
                'option' => $context->commandFragment($subName),
            ]);

            return null;
        }

        $context->replyRaw(implode("\n", $this->formatter->renderCommandHelp($adapter, $handler)));

        return null;
    }

    /**
     * @return array{name: string, desc_key: string, help_key: string, syntax_key: string, example_key: string}|null
     */
    private function findSubCommand(MemoServCommandInterface $handler, string $subName): ?array
    {
        return array_find($handler->getSubCommandHelp(), static fn (array $sub): bool => $sub['name'] === $subName);
    }
}
