<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use App\OperServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\OperServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;

final readonly class OperServHelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    private const array HELP_GROUPS = [
        ['group_key' => 'help.group.operators_roles', 'commands' => ['IRCOP', 'ROLE'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.network_security', 'commands' => ['GLINE', 'KILL'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.communication', 'commands' => ['MOTD', 'GLOBAL'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.low_level', 'commands' => ['RAW'], 'admin' => false, 'subgroup' => false],
    ];

    public function __construct(private OperServContext $context) {}

    public function reply(string $key, array $params = []): void
    {
        $this->context->reply($key, $params);
    }

    public function replyRaw(string $message): void
    {
        $this->context->replyRaw($message);
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->context->trans($key, $params);
    }

    /** @return iterable<HelpableCommandInterface> */
    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->commandRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool
    {
        if (!$command instanceof OperServCommandInterface) {
            return false;
        }

        $permission = $command->getRequiredPermission();
        if (null !== $permission) {
            return $this->context->isAuthorized($permission);
        }

        return !$command->isOperOnly() || $this->context->isAuthorized(null);
    }

    public function canViewCommandInHelp(HelpableCommandInterface $command): bool
    {
        return $this->shouldShowCommandInGeneralHelp($command);
    }

    public function getHelpGroups(): array
    {
        return self::HELP_GROUPS;
    }

    /** @return iterable<HelpableCommandInterface> */
    public function getIrcopCommands(): iterable
    {
        return [];
    }

    public function hasIrcopAccess(): bool
    {
        return false;
    }
}
