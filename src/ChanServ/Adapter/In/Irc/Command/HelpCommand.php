<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\ChanServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

use function array_find;
use function implode;
use function strtoupper;

/**
 * Handles the HELP command: HELP [command] [subcommand].
 *
 * Lists commands (filtered by IRCd mode support and IRCop permission) or shows help for a command.
 * Design aligned with NickServ via UnifiedHelpFormatter.
 */
final readonly class HelpCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    public function __construct(
        private UnifiedHelpFormatter $formatter,
        private ChanServOperatorAccess $operatorAccess,
        private int $inactivityExpiryDays = 0,
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
        return new CommandArgumentDefinition(
            syntaxKey: 'help.syntax',
            arguments: [
                new Argument('command', optional: true),
                new Argument('option', optional: true),
            ],
        );
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

    /** @return array<string, mixed> */
    public function getHelpParams(): array
    {
        return [];
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

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
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
            $context->reply('unknown_command', ['command' => $targetCmd]);

            return;
        }

        $adapter = new HelpFormatterContextAdapter($context, $this->operatorAccess);
        if (!$adapter->canViewCommandInHelp($handler)) {
            $context->reply('unknown_command', ['command' => $targetCmd]);

            return;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                $context->replyRaw(implode("\n", $this->formatter->renderSubCommandHelp($adapter, $handler->getName(), $subCmd)));

                return;
            }

            $context->reply('unknown_help_option', [
                'command' => $handler->getName(),
                'option' => strtoupper($context->args[1]),
            ]);

            return;
        }

        $context->replyRaw(implode("\n", $this->formatter->renderCommandHelp($adapter, $handler)));
    }

    private function showGeneralHelp(ChanServContext $context): void
    {
        $adapter = new HelpFormatterContextAdapter($context, $this->operatorAccess);
        $inactivityNotice = null;
        if ($this->inactivityExpiryDays > 0) {
            $inactivityNotice = $context->trans('help.intro_expiration', [
                'label' => $context->trans('help.intro_expiration_label'),
                'days' => $this->inactivityExpiryDays,
            ]);
        }
        $lines = $this->formatter->renderGeneralHelp($adapter);
        if (null !== $inactivityNotice) {
            $lines[] = ' ';
            $lines[] = $inactivityNotice;
        }
        $lines[] = $this->formatter->renderFooter($adapter);
        $context->replyRaw(implode("\n", $lines));
    }

    /** @return array{name: string, desc_key: string, help_key: string, syntax_key: string, example_key: string}|null */
    private function findSubCommand(ChanServCommandInterface $handler, string $name): ?array
    {
        return array_find($handler->getSubCommandHelp(), static fn (array $sub): bool => $name === strtoupper($sub['name']));
    }
}
