<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Security\IrcopPermissionDetector;
use App\Application\Security\PermissionRegistry;
use App\Application\Shared\Help\HelpableCommandInterface;
use App\Application\Shared\Help\HelpFormatterContextInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Domain\Entity\RegisteredNick;

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
        $permission = $command instanceof ChanServCommandInterface ? $command->getRequiredPermission() : null;
        if (null !== $permission && IrcopPermissionDetector::isIrcopPermission($permission)) {
            return false;
        }

        if ($command->isOperOnly()) {
            return $this->context->sender->isOper ?? false;
        }

        return $this->shouldShowByName($command);
    }

    private function shouldShowByName(HelpableCommandInterface $command): bool
    {
        $name = $command->getName();
        if (isset(self::MODE_DEPENDENT_COMMANDS[$name])) {
            $mode = self::MODE_DEPENDENT_COMMANDS[$name];
            $map = [
                'a' => $this->context->getChannelModeSupport()->hasAdmin(),
                'h' => $this->context->getChannelModeSupport()->hasHalfOp(),
            ];

            return $map[$mode];
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

        return $this->resolveIrcopCommands($sender, $account, $nickLower);
    }

    /**
     * @return iterable<HelpableCommandInterface>
     */
    private function resolveIrcopCommands(SenderView $sender, RegisteredNick $account, string $nickLower): iterable
    {
        if ($this->rootRegistry->isRoot($nickLower)) {
            return $this->filterIrcopCommands($this->context->getRegistry()->all());
        }

        if (!$sender->isOper) {
            return [];
        }

        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            (int) $account->getId(),
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

        return $this->checkIrcopAccess($sender, $account, $nickLower);
    }

    private function checkIrcopAccess(SenderView $sender, RegisteredNick $account, string $nickLower): bool
    {
        if ($this->rootRegistry->isRoot($nickLower)) {
            return true;
        }

        if ($sender->isOper) {
            $servicePermissions = $this->permissionRegistry->getPermissionsByService()['ChanServ'] ?? [];
            if (array_any($servicePermissions, fn (string $permission): bool => $this->accessHelper->hasPermission((int) $account->getId(), $nickLower, $permission))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<ChanServCommandInterface> $commands
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
     * @param iterable<ChanServCommandInterface> $commands
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
