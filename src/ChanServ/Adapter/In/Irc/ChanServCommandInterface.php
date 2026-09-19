<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\ChanServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\Irc\Application\Port\In\Command\CommandOutcome;

/**
 * Contract every ChanServ command module must implement.
 *
 * Commands are tagged with 'chanserv.command' and registered in ChanServCommandRegistry.
 */
interface ChanServCommandInterface extends HelpableCommandInterface
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

    /**
     * Whether this command is allowed on suspended channels.
     * Commands like SUSPEND, UNSUSPEND, INFO, and DROP should return true.
     */
    public function allowsSuspendedChannel(): bool;

    /**
     * Whether this command is allowed on forbidden channels.
     * Commands like FORBID (update reason), UNFORBID, and INFO should return true.
     */
    public function allowsForbiddenChannel(): bool;

    /**
     * Whether this command uses isLevelFounder to bypass channel-level access checks.
     * Commands that return true will be audited as level_founder actions
     * when executed by an IRCop with chanserv.level_founder permission on channels
     * they are not the real founder of.
     */
    public function usesLevelFounder(): bool;

    /**
     * @return CommandOutcome|void|null
     */
    public function execute(ChanServContext $context);
}
