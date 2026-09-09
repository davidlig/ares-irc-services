<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Help;

/**
 * Port for the unified HELP formatter. Abstracts reply, translation and command
 * listing so NickServ and ChanServ can share the same help layout (header, options, syntax, footer).
 */
interface HelpFormatterContextInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function reply(string $key, array $params = []): void;

    public function replyRaw(string $message): void;

    /**
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string;

    /**
     * Commands to list in general HELP.
     *
     * @return iterable<HelpableCommandInterface>
     */
    public function getCommandsForGeneralHelp(): iterable;

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool;

    /**
     * Returns IRCop commands that the current user has permission for.
     * Used to show a separated section in HELP for IRCop-only commands.
     *
     * @return iterable<HelpableCommandInterface>
     */
    public function getIrcopCommands(): iterable;

    /**
     * Returns true if the user is a root user or an IRCop with at least one permission.
     * Used to decide whether to show the IRCop commands section.
     */
    public function hasIrcopAccess(): bool;
}
