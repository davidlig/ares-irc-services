<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc;

use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Security\NickServPermission;
use App\Shared\Application\Help\HelpableCommandInterface;
use App\Shared\Application\Help\HelpFormatterContextInterface;

use function str_starts_with;
use function strtolower;

/**
 * Adapter that exposes HelpFormatterContextInterface from NickServContext.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    public function __construct(
        private NickServContext $context,
        private NickServOperatorAccess $operatorAccess,
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
        if (null !== $permission && self::isNickServIrcopPermission($permission)) {
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

        if (null === $sender || null === $account || !$sender->isIdentified) {
            return [];
        }

        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            strtolower($sender->nick),
            (int) $account->getId(),
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
            (int) $account->getId(),
            $sender->isIdentified,
            $sender->isOper,
            NickServPermission::allIrcop(),
        );
    }

    /**
     * @param iterable<NickServCommandInterface> $commands
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
                && self::isNickServIrcopPermission($permission)
                && $this->operatorAccess->hasPermission($nickname, $accountId, $identified, $ircOperator, $permission)) {
                yield $command;
            }
        }
    }

    private static function isNickServIrcopPermission(string $permission): bool
    {
        return str_starts_with($permission, 'nickserv.');
    }
}
