<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command;

use App\Application\Shared\Help\HelpFormatterContextInterface;

final readonly class OperServHelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    public function __construct(
        private OperServContext $context,
    ) {
    }

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

    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->getRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(object $command): bool
    {
        if ($command->isOperOnly()) {
            return $this->context->isRoot();
        }

        $requiredPermission = $command->getRequiredPermission();
        if (null !== $requiredPermission) {
            $sender = $this->context->getSender();
            if (null === $sender) {
                return false;
            }

            $account = $this->context->getSenderAccount();
            if (null === $account) {
                return $this->context->isRoot();
            }

            $nickLower = strtolower($sender->nick);

            return $this->context->getAccessHelper()->hasPermission(
                $account->getId(),
                $nickLower,
                $requiredPermission,
            );
        }

        return true;
    }

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
