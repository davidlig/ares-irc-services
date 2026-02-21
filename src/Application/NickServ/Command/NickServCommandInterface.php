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
     * Whether only IRC network operators may use this command.
     * Non-oper users receive a permission-denied notice.
     */
    public function isOperOnly(): bool;

    /** Execute the command. All communication is done via $context->reply(). */
    public function execute(NickServContext $context): void;
}
