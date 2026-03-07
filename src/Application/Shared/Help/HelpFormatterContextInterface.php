<?php

declare(strict_types=1);

namespace App\Application\Shared\Help;

/**
 * Port for the unified HELP formatter. Abstracts reply, translation and command
 * listing so NickServ and ChanServ can share the same help layout (header, options, syntax, footer).
 */
interface HelpFormatterContextInterface
{
    public function reply(string $key, array $params = []): void;

    public function replyRaw(string $message): void;

    public function trans(string $key, array $params = []): string;

    /**
     * Commands to list in general HELP. Each element must have getName(), getOrder(),
     * getShortDescKey(), getSyntaxKey(), getHelpKey(), getSubCommandHelp(), isOperOnly().
     *
     * @return iterable<object>
     */
    public function getCommandsForGeneralHelp(): iterable;

    public function shouldShowCommandInGeneralHelp(object $command): bool;
}
