<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\Application\Shared\Help\HelpableCommandInterface;
use App\Application\Shared\Help\HelpFormatterContextInterface;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Irc\Application\Port\In\SenderView;

use function str_starts_with;
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
        private ChanServOperatorAccess $operatorAccess,
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
        if (null !== $permission && self::isChanServIrcopPermission($permission)) {
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

        if (null === $sender || null === $account || !$sender->isIdentified) {
            return [];
        }

        $nickLower = strtolower($sender->nick);

        return $this->resolveIrcopCommands($sender, $account, $nickLower);
    }

    /**
     * @return iterable<HelpableCommandInterface>
     */
    private function resolveIrcopCommands(SenderView $sender, ChanAccountView $account, string $nickLower): iterable
    {
        if ($this->operatorAccess->isRoot($nickLower)) {
            return $this->filterIrcopCommands($this->context->getRegistry()->all());
        }

        if (!$sender->isOper) {
            return [];
        }

        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            $account->id,
            $nickLower,
        );
    }

    public function hasIrcopAccess(): bool
    {
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        if (null === $sender || null === $account || !$sender->isIdentified) {
            return false;
        }

        $nickLower = strtolower($sender->nick);

        if ($this->operatorAccess->isRoot($nickLower)) {
            return true;
        }

        if ($sender->isOper) {
            return $this->operatorAccess->hasAnyPermission($account->id, $nickLower, ChanServPermission::allIrcop());
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
            if (null !== $permission && self::isChanServIrcopPermission($permission)) {
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
                && self::isChanServIrcopPermission($permission)
                && $this->operatorAccess->hasPermission($nickId, $nickLower, $permission)) {
                yield $command;
            }
        }
    }

    private static function isChanServIrcopPermission(string $permission): bool
    {
        return str_starts_with($permission, 'chanserv.');
    }
}
