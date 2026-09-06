<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Security\IrcopPermissionDetector;
use App\Application\Security\PermissionRegistry;
use App\Application\Shared\Help\HelpableCommandInterface;
use App\Application\Shared\Help\HelpFormatterContextInterface;

use function strtolower;

/**
 * Adapter that exposes HelpFormatterContextInterface from NickServContext.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    public function __construct(
        private NickServContext $context,
        private IrcopAccessHelper $accessHelper,
        private RootUserRegistry $rootRegistry,
        private PermissionRegistry $permissionRegistry,
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
        // Commands with IRCop permissions are not shown in general help
        $permission = $command instanceof NickServCommandInterface ? $command->getRequiredPermission() : null;
        if (null !== $permission && IrcopPermissionDetector::isIrcopPermission($permission)) {
            return false;
        }

        // Legacy: isOperOnly() commands shown only to opers
        if ($command->isOperOnly()) {
            return $this->context->sender->isOper ?? false;
        }

        return true;
    }

    /**
     * @return iterable<HelpableCommandInterface>
     */
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

        $allCommands = $this->context->getRegistry()->all();

        return $sender->isOper
            ? $this->filterByPermission($allCommands, (int) $account->getId(), $nickLower)
            : [];
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

        $result = false;
        if ($sender->isOper) {
            $servicePermissions = $this->permissionRegistry->getPermissionsByService()['NickServ'] ?? [];
            $result = array_any($servicePermissions, fn (string $permission): bool => $this->accessHelper->hasPermission((int) $account->getId(), $nickLower, $permission));
        }

        return $result;
    }

    /**
     * @param iterable<NickServCommandInterface> $commands
     *
     * @return iterable<HelpableCommandInterface>
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
     * @param iterable<NickServCommandInterface> $commands
     *
     * @return iterable<HelpableCommandInterface>
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
