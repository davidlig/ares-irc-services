<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

/**
 * HALFOP <#channel> <nickname>. ChanServ gives +h. Requires HALFOPDEHALFOP; only if IRCd supports halfop mode.
 */
final readonly class HalfopCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;
    use ManualRankCommandPresentation;

    public function getName(): string
    {
        return 'HALFOP';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'halfop.syntax',
            arguments: [
                new Argument('channel'),
                new Argument('nickname'),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'halfop.help';
    }

    public function getExampleKey(): string
    {
        return 'halfop.example';
    }

    public function getOrder(): int
    {
        return 22;
    }

    public function getShortDescKey(): string
    {
        return 'halfop.short';
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
