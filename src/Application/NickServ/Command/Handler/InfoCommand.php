<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * INFO <nickname>
 *
 * Shows public registration details for a nickname.
 * Email is only visible to the owner (same UID) or IRC operators (future).
 * Private accounts hide the info block from non-owners.
 */
final class InfoCommand implements NickServCommandInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NetworkUserRepositoryInterface $userRepository,
    ) {
    }

    public function getName(): string
    {
        return 'INFO';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'info.syntax';
    }

    public function getHelpKey(): string
    {
        return 'info.help';
    }

    public function getOrder(): int
    {
        return 3;
    }

    public function getShortDescKey(): string
    {
        return 'info.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function execute(NickServContext $context): void
    {
        $sender     = $context->sender;
        $targetNick = $context->args[0];

        $account = $this->nickRepository->findByNick($targetNick);

        if ($account === null) {
            $context->reply('info.not_registered', ['nickname' => $targetNick]);
            return;
        }

        // Is the requester the owner?
        $isOwner = $sender !== null
            && strcasecmp($sender->getNick()->value, $account->getNickname()) === 0;

        // If the account is private and the requester is not the owner (or oper in the future)
        if ($account->isPrivate() && !$isOwner) {
            $context->reply('info.private', ['nickname' => $account->getNickname()]);
            return;
        }

        $context->reply('info.header', ['nickname' => $account->getNickname()]);

        // Registration date
        $context->reply('info.registered_at', [
            'date' => $account->getRegisteredAt()->format('d/m/Y H:i'),
        ]);

        // Last seen — if online show ONLINE, otherwise the date
        try {
            $onlineUser = $this->userRepository->findByNick(new Nick($account->getNickname()));
        } catch (\InvalidArgumentException) {
            $onlineUser = null;
        }

        if ($onlineUser !== null) {
            $context->reply('info.last_seen_online');
        } elseif ($account->getLastSeenAt() !== null) {
            $context->reply('info.last_seen_at', [
                'date' => $account->getLastSeenAt()->format('d/m/Y H:i'),
            ]);
        } else {
            $context->reply('info.last_seen_never');
        }

        // Last quit message
        if ($account->getLastQuitMessage() !== null) {
            $context->reply('info.last_quit', ['message' => $account->getLastQuitMessage()]);
        }

        // Email — visible only to the owner (and future oper support)
        if ($isOwner) {
            $context->reply('info.email', ['email' => $account->getEmail()]);
        }

        $context->reply('info.footer');
    }
}
