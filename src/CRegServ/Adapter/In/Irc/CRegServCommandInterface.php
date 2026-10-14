<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc;

use App\CRegServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;

/**
 * Contract every CReG command module must implement.
 *
 * Commands are tagged with 'cregserv.command' and registered in CRegServCommandRegistry.
 */
interface CRegServCommandInterface extends HelpableCommandInterface
{
    public function getName(): string;

    /** @return string[] */
    public function getAliases(): array;

    public function getMinArgs(): int;

    public function getSyntaxKey(): string;

    public function getHelpKey(): string;

    public function getOrder(): int;

    public function getShortDescKey(): string;

    /**
     * @return array<int, array{name: string, desc_key: string, help_key: string, syntax_key: string}>
     */
    public function getSubCommandHelp(): array;

    public function isOperOnly(): bool;

    /** Null = no permission; 'IDENTIFIED' = sender must have a registered nick (senderAccount). */
    public function getRequiredPermission(): ?string;

    public function execute(CRegServContext $context): ?CommandOutcome;
}
