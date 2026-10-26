<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

/**
 * ADMIN <#channel> <nickname>. ChanServ gives +a. Requires ADMINDEADMIN; only if IRCd supports admin mode.
 */
final readonly class AdminCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;
    use ManualRankCommandPresentation;

    public function getName(): string
    {
        return 'ADMIN';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'admin.syntax',
            arguments: [
                new Argument('channel'),
                new Argument('nickname'),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'admin.help';
    }

    public function getExampleKey(): string
    {
        return 'admin.example';
    }

    public function getOrder(): int
    {
        return 18;
    }

    public function getShortDescKey(): string
    {
        return 'admin.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return 'IDENTIFIED';
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return true;
    }
}
