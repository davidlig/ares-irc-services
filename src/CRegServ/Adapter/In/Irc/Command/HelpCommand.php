<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc\Command;

use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationCheckerInterface;
use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\CRegServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\Irc\Application\Port\In\Command\CommandOutcome;

use function strtoupper;

/**
 * Handles the HELP command: HELP [command].
 *
 * Lists commands (the oper-only ones only for an oper holding their permission)
 * or shows help for a command. Port of pulsar CRegServ's do_help (three tiers in
 * pulsar; ares has no services-admin tier, so the ChanServ/NickServ precedent of
 * one user section plus one IRCop section applies).
 */
final readonly class HelpCommand implements CRegServCommandInterface
{
    public function __construct(
        private UnifiedHelpFormatter $formatter,
        private CRegServAuthorizationCheckerInterface $authorizationChecker,
    ) {}

    public function getName(): string
    {
        return 'HELP';
    }

    /** @return list<string> */
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

    /**
     * @return array<int, array{name: string, desc_key: string, help_key: string, syntax_key: string}>
     */
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

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $adapter = new HelpFormatterContextAdapter($context, $this->authorizationChecker);

        if (empty($context->args)) {
            $lines = $this->formatter->renderGeneralHelp($adapter);
            $lines[] = $this->formatter->renderFooter($adapter);
            $context->replyRaw(implode("\n", $lines));

            return null;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler = $context->getRegistry()->find($targetCmd);

        if (null === $handler || !$adapter->canViewCommandInHelp($handler)) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);

            return null;
        }

        $context->replyRaw(implode("\n", $this->formatter->renderCommandHelp($adapter, $handler)));

        return null;
    }
}
