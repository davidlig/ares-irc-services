<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Security\IrcopPermissionDetector;
use App\Application\Security\PermissionRegistry;
use App\Application\Shared\Help\HelpFormatterContextInterface;

use function strtolower;

/**
 * Adapter from NickServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    public function __construct(
        private NickServContext $context,
        private IrcopAccessHelper $accessHelper,
        private RootUserRegistry $rootRegistry,
        private PermissionRegistry $permissionRegistry,
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
        // Commands with IRCop permissions are not shown in general help
        $permission = $command->getRequiredPermission();
        if (null !== $permission && IrcopPermissionDetector::isIrcopPermission($permission)) {
            return false;
        }

        // Legacy: isOperOnly() commands shown only to opers
        if ($command->isOperOnly()) {
            return $this->context->sender?->isOper ?? false;
        }

        return true;
    }

    public function getIrcopCommands(): iterable
    {
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        // Must be identified to have IRCop permissions
        if (null === $sender || null === $account) {
            return [];
        }

        $nickLower = strtolower($sender->nick);

        // Root users see all IRCop commands
        if ($this->rootRegistry->isRoot($nickLower)) {
            return $this->filterIrcopCommands($this->context->getRegistry()->all());
        }

        // Must be IRCop and have at least one permission
        if (!$sender->isOper) {
            return [];
        }

        // Filter by permissions
        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            $account->getId(),
            $nickLower
        );
    }

    public function hasIrcopAccess(): bool
    {
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        if (null === $sender || null === $account) {
            return false;
        }

        $nickLower = strtolower($sender->nick);

        // Root users always have IRCop access
        if ($this->rootRegistry->isRoot($nickLower)) {
            return true;
        }

        // IRCops with at least one permission
        if ($sender->isOper) {
            $servicePermissions = $this->permissionRegistry->getPermissionsByService()['NickServ'] ?? [];
            foreach ($servicePermissions as $permission) {
                if ($this->accessHelper->hasPermission($account->getId(), $nickLower, $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param iterable<object> $commands
     *
     * @return iterable<object>
     */
    private function filterIrcopCommands(iterable $commands): iterable
    {
        foreach ($commands as $command) {
            $permission = $command->getRequiredPermission();
            if (null !== $permission && IrcopPermissionDetector::isIrcopPermission($permission)) {
                yield $command;
            }
        }
    }

    /**
     * @param iterable<object> $commands
     *
     * @return iterable<object>
     */
    private function filterByPermission(iterable $commands, int $nickId, string $nickLower): iterable
    {
        foreach ($commands as $command) {
            $permission = $command->getRequiredPermission();
            if (null !== $permission
                && IrcopPermissionDetector::isIrcopPermission($permission)
                && $this->accessHelper->hasPermission($nickId, $nickLower, $permission)) {
                yield $command;
            }
        }
    }
}
