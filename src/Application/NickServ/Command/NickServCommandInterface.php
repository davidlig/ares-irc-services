<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

/**
 * Contract every NickServ command module must implement.
 *
 * To add a new command: create a class implementing this interface,
 * tag it with 'nickserv.command' in services.yaml (or rely on autoconfigure),
 * and it will be automatically registered in the NickServCommandRegistry.
 */
interface NickServCommandInterface
{
    /** Primary command name (uppercase). E.g. "REGISTER". */
    public function getName(): string;

    /**
     * Alternative names that also trigger this command.
     *
     * @return string[]
     */
    public function getAliases(): array;

    /**
     * Minimum number of arguments required.
     * If the user provides fewer, NickServService sends the syntax hint.
     */
    public function getMinArgs(): int;

    /**
     * Translation key for the command's usage syntax line.
     * E.g. "register.syntax" → resolved from nickserv domain.
     */
    public function getSyntaxKey(): string;

    /**
     * Translation key for the full help text of this command.
     * E.g. "register.help".
     */
    public function getHelpKey(): string;

    /**
     * Display order in the general HELP listing (lower = shown first).
     * Use high values (e.g. 99) to place utility commands at the end.
     */
    public function getOrder(): int;

    /**
     * Translation key for the one-line description shown in the general HELP listing.
     * E.g. "register.short".
     */
    public function getShortDescKey(): string;

    /**
     * Sub-commands shown when HELP <command> is requested for commands with options.
     * Each entry must contain:
     *   - name       Primary name shown in the listing (e.g. "PASSWORD")
     *   - desc_key   Translation key for the one-line description
     *   - help_key   Translation key for the full help text (HELP CMD SUBOPTION)
     *   - syntax_key Translation key for the usage line.
     *
     * Return an empty array for commands without sub-options.
     *
     * @return array<int, array{name: string, desc_key: string, help_key: string, syntax_key: string}>
     */
    public function getSubCommandHelp(): array;

    /**
     * Whether only IRC network operators may use this command.
     * Non-oper users receive a permission-denied notice.
     */
    public function isOperOnly(): bool;

    /**
     * Permission attribute required to run this command (e.g. identified owner).
     * Null means no permission check. Checked via Symfony Security isGranted() before execute().
     */
    public function getRequiredPermission(): ?string;

    /**
     * Parameters to inject into the help translation.
     * Override this method if the help text needs dynamic values (e.g. configurable prefixes).
     *
     * @return array<string, mixed>
     */
    public function getHelpParams(): array;

    /** Execute the command. All communication is done via $context->reply(). */
    public function execute(NickServContext $context): void;
}
