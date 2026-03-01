<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command;

/**
 * Contract every ChanServ command module must implement.
 *
 * Commands are tagged with 'chanserv.command' and registered in ChanServCommandRegistry.
 */
interface ChanServCommandInterface
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

    /** Null = no permission; otherwise e.g. identified for REGISTER. */
    public function getRequiredPermission(): ?string;

    public function execute(ChanServContext $context): void;
}
