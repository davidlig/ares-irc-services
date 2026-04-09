<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Security\IrcopPermissionDetector;
use App\Application\Security\PermissionRegistry;
use App\Application\Shared\Help\HelpFormatterContextInterface;

use function strtolower;

/**
 * Adapter from ChanServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    /** Commands that require specific mode support to show (name => mode letter). */
    private const array MODE_DEPENDENT_COMMANDS = [
        'ADMIN' => 'a',
        'DEADMIN' => 'a',
        'HALFOP' => 'h',
        'DEHALFOP' => 'h',
    ];

    public function __construct(
        private ChanServContext $context,
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
        $permission = $command->getRequiredPermission();
        if (null !== $permission && IrcopPermissionDetector::isIrcopPermission($permission)) {
            return false;
        }

        if ($command->isOperOnly()) {
            return $this->context->sender?->isOper ?? false;
        }

        $name = $command->getName();
        if (isset(self::MODE_DEPENDENT_COMMANDS[$name])) {
            $mode = self::MODE_DEPENDENT_COMMANDS[$name];

            return ['a' => $this->context->getChannelModeSupport()->hasAdmin(), 'h' => $this->context->getChannelModeSupport()->hasHalfOp()][$mode] ?? false;
        }

        return true;
    }

    public function getIrcopCommands(): iterable
    {
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        if (null === $sender || null === $account) {
            return [];
        }

        $nickLower = strtolower($sender->nick);

        if ($this->rootRegistry->isRoot($nickLower)) {
            return $this->filterIrcopCommands($this->context->getRegistry()->all());
        }

        if (!$sender->isOper) {
            return [];
        }

        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            $account->getId(),
            $nickLower,
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

        if ($this->rootRegistry->isRoot($nickLower)) {
            return true;
        }

        if ($sender->isOper) {
            $servicePermissions = $this->permissionRegistry->getPermissionsByService()['ChanServ'] ?? [];
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
