<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command;

use App\Application\Shared\Help\HelpableCommandInterface;
use App\Application\Shared\Help\HelpFormatterContextInterface;

final readonly class OperServHelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    public function __construct(
        private OperServContext $context,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public function reply(string $key, array $params = []): void
    {
        $this->context->reply($key, $params);
    }

    public function replyRaw(string $message): void
    {
        $this->context->replyRaw($message);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->context->trans($key, $params);
    }

    /**
     * @return iterable<HelpableCommandInterface>
     */
    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->getRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool
    {
        $requiredPermission = $command instanceof OperServCommandInterface ? $command->getRequiredPermission() : null;
        if (null !== $requiredPermission) {
            return $this->context->isAuthorized($requiredPermission);
        }

        return !$command->isOperOnly() || $this->context->isAuthorized(null);
    }

    /**
     * @return iterable<HelpableCommandInterface>
     */
    public function getIrcopCommands(): iterable
    {
        // OperServ shows all commands based on isRoot/isOper status
        // No separate IRCop section needed
        return [];
    }

    public function hasIrcopAccess(): bool
    {
        // OperServ uses different logic (root/oper checks)
        return false;
    }
}
