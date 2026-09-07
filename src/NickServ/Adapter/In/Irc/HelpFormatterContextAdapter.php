<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc;

use App\Application\Shared\Help\HelpableCommandInterface;
use App\Application\Shared\Help\HelpFormatterContextInterface;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Security\NickServPermission;

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

        if (null === $sender || null === $account) {
            return [];
        }

        $nickLower = strtolower($sender->nick);

        if ($this->operatorAccess->isRoot($nickLower)) {
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

        if ($this->operatorAccess->isRoot($nickLower)) {
            return true;
        }

        $result = false;
        if ($sender->isOper) {
            $result = $this->operatorAccess->hasAnyPermission((int) $account->getId(), $nickLower, NickServPermission::allIrcop());
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
            if (null !== $permission && self::isNickServIrcopPermission($permission)) {
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
                && self::isNickServIrcopPermission($permission)
                && $this->operatorAccess->hasPermission($nickId, $nickLower, $permission)) {
                yield $command;
            }
        }
    }

    private static function isNickServIrcopPermission(string $permission): bool
    {
        return str_starts_with($permission, 'nickserv.');
    }
}
