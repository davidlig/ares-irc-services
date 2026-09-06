<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command;

use App\Application\Command\CommandOutcome;
use App\Application\Shared\Help\HelpableCommandInterface;

/**
 * Contract every MemoServ command module must implement.
 *
 * Commands are tagged with 'memoserv.command' and registered in MemoServCommandRegistry.
 */
interface MemoServCommandInterface extends HelpableCommandInterface
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

    /**
     * @return CommandOutcome|void|null
     */
    public function execute(MemoServContext $context);
}
