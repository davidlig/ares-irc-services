<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;

/**
 * DEADMIN <#channel> <nickname>. ChanServ removes +a. Only if IRCd supports admin mode.
 */
final readonly class DeadminCommand implements ChanServCommandInterface
{
    use ManualRankCommandPresentation;

    public function getName(): string
    {
        return 'DEADMIN';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'deadmin.syntax';
    }

    public function getHelpKey(): string
    {
        return 'deadmin.help';
    }

    public function getOrder(): int
    {
        return 19;
    }

    public function getShortDescKey(): string
    {
        return 'deadmin.short';
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
