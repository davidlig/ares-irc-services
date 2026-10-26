<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

/**
 * DEOP <#channel> <nickname>. ChanServ removes +o.
 */
final readonly class DeopCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;
    use ManualRankCommandPresentation;

    public function getName(): string
    {
        return 'DEOP';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'deop.syntax',
            arguments: [
                new Argument('channel'),
                new Argument('nickname'),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'deop.help';
    }

    public function getExampleKey(): string
    {
        return 'deop.example';
    }

    public function getOrder(): int
    {
        return 21;
    }

    public function getShortDescKey(): string
    {
        return 'deop.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return 'IDENTIFIED';
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return true;
    }
}
