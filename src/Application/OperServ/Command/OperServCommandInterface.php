<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command;

use App\Application\Command\CommandOutcome;
use App\Application\Shared\Help\HelpableCommandInterface;

interface OperServCommandInterface extends HelpableCommandInterface
{
    public function getName(): string;

    /** @return string[] */
    public function getAliases(): array;

    public function getMinArgs(): int;

    public function getSyntaxKey(): string;

    public function getHelpKey(): string;

    public function getOrder(): int;

    public function getShortDescKey(): string;

    /** @return array<int, array{name: string, desc_key: string, help_key: string, syntax_key: string}> */
    public function getSubCommandHelp(): array;

    public function isOperOnly(): bool;

    public function getRequiredPermission(): ?string;

    /**
     * @return CommandOutcome|void|null
     */
    public function execute(OperServContext $context);
}
