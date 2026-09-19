<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\ChanServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\ChanServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;

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

        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            strtolower($sender->nick),
            $account->id,
            $sender->isIdentified,
            $sender->isOper,
        );
    }

    public function hasIrcopAccess(): bool
    {
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        if (null === $sender || null === $account || !$sender->isIdentified) {
            return false;
        }

        return $this->operatorAccess->hasAnyPermission(
            strtolower($sender->nick),
            $account->id,
            $sender->isIdentified,
            $sender->isOper,
            ChanServPermission::allIrcop(),
        );
    }

    /**
     * @param iterable<ChanServCommandInterface> $commands
     *
     * @return iterable<HelpableCommandInterface>
     */
    private function filterByPermission(
        iterable $commands,
        string $nickname,
        int $accountId,
        bool $identified,
        bool $ircOperator,
    ): iterable {
        foreach ($commands as $command) {
            $permission = $command->getRequiredPermission();
            if (null !== $permission
                && self::isChanServIrcopPermission($permission)
                && $this->operatorAccess->hasPermission($nickname, $accountId, $identified, $ircOperator, $permission)) {
                yield $command;
            }
        }
    }

    private static function isChanServIrcopPermission(string $permission): bool
    {
        return str_starts_with($permission, 'chanserv.');
    }
}
