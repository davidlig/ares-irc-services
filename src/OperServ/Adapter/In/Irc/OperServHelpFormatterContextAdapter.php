<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use App\OperServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\OperServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;

final readonly class OperServHelpFormatterContextAdapter implements HelpFormatterContextInterface
{
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
