<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\ChanServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;

use function array_find;
use function strtoupper;

/**
 * Handles the HELP command: HELP [command] [subcommand].
 *
 * Lists commands (filtered by IRCd mode support and IRCop permission) or shows help for a command.
 * Design aligned with NickServ via UnifiedHelpFormatter.
 */
final readonly class HelpCommand implements ChanServCommandInterface
{
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
            $context->reply('help.unknown_command', ['command' => $targetCmd]);

            return;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                $adapter = new HelpFormatterContextAdapter($context, $this->operatorAccess);
                $this->formatter->showSubCommandHelp($adapter, $handler->getName(), $subCmd);

                return;
            }
        }

        $adapter = new HelpFormatterContextAdapter($context, $this->operatorAccess);
        $this->formatter->showCommandHelp($adapter, $handler);
    }

    private function showGeneralHelp(ChanServContext $context): void
    {
        $adapter = new HelpFormatterContextAdapter($context, $this->operatorAccess);
        $this->formatter->showGeneralHelp($adapter);
        if ($this->inactivityExpiryDays > 0) {
            $context->replyRaw(' ');
            $context->reply('help.intro_expiration', ['%days%' => $this->inactivityExpiryDays]);
        }
        $context->reply('help.footer');
    }

    /** @return array{name: string, desc_key: string, help_key: string, syntax_key: string}|null */
    private function findSubCommand(ChanServCommandInterface $handler, string $name): ?array
    {
        return array_find($handler->getSubCommandHelp(), static fn (array $sub): bool => $name === strtoupper($sub['name']));
    }
}
