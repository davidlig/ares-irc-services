<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Fake;

use App\CRegServ\Adapter\In\Irc\CRegServCommandInterface;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\Irc\Application\Port\In\Command\CommandOutcome;

/**
 * An oper-only command that declares no permission at all, so the oper gate alone is what
 * must stop it, and that records whether it ever ran.
 */
final class OperOnlyCommandDouble implements CRegServCommandInterface
{
    public bool $executed = false;

    public function getName(): string
    {
        return 'APROBAR';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'aprobar.syntax';
    }

    public function getHelpKey(): string
    {
        return 'aprobar.help';
    }

    public function getOrder(): int
    {
        return 0;
    }

    public function getShortDescKey(): string
    {
        return 'aprobar.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function execute(CRegServContext $context): ?CommandOutcome
    {
        $this->executed = true;

        return null;
    }
}
