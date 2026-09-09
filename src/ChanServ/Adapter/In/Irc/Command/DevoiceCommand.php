<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;

/**
 * DEVOICE <#channel> <nickname>. ChanServ removes +v.
 */
final readonly class DevoiceCommand implements ChanServCommandInterface
{
    use ManualRankCommandPresentation;

    public function getName(): string
    {
        return 'DEVOICE';
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
        return 'devoice.syntax';
    }

    public function getHelpKey(): string
    {
        return 'devoice.help';
    }

    public function getOrder(): int
    {
        return 25;
    }

    public function getShortDescKey(): string
    {
        return 'devoice.short';
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
