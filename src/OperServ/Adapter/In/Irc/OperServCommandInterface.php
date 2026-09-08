<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use App\Application\Shared\Help\HelpableCommandInterface;

/**
 * IRC metadata and presentation boundary for an OperServ operation.
 *
 * Command implementations parse IRC arguments and present semantic use-case
 * results; they never contain authorization or persistence policy.
 */
interface OperServCommandInterface extends HelpableCommandInterface
{
    public function getName(): string;

    /** @return list<string> */
    public function getAliases(): array;

    public function getMinArgs(): int;

    public function getSyntaxKey(): string;

    public function getHelpKey(): string;

    public function getOrder(): int;

    public function getShortDescKey(): string;

    /** @return list<array{name: string, desc_key: string, help_key: string, syntax_key: string}> */
    public function getSubCommandHelp(): array;

    public function isOperOnly(): bool;

    public function getRequiredPermission(): ?string;

    public function execute(OperServContext $context): void;
}
