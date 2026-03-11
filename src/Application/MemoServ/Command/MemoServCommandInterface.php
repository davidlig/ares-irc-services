<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command;

/**
 * Contract every MemoServ command module must implement.
 *
 * Commands are tagged with 'memoserv.command' and registered in MemoServCommandRegistry.
 */
interface MemoServCommandInterface
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

    public function execute(MemoServContext $context): void;
}
