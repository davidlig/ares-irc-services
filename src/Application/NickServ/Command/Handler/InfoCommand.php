<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use InvalidArgumentException;

/**
 * INFO <nickname>.
 *
 * Shows public registration details for a nickname.
 * Email is only visible to the owner (same UID) or IRC operators (future).
 * Private accounts hide the info block from non-owners.
 */
final readonly class InfoCommand implements NickServCommandInterface
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
        return 6;
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

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        $targetNick = $context->args[0];

        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account || $account->isForbidden() || $account->isPending()) {
            $context->reply('info.not_registered', ['nickname' => $targetNick]);

            return;
        }

        $isOwner = null !== $sender
            && 0 === strcasecmp($sender->getNick()->value, $account->getNickname());
        $isOwnerIdentified = $isOwner && $sender->isIdentified();

        if ($account->isPrivate() && !$isOwner) {
            $context->reply('info.private', ['nickname' => $account->getNickname()]);

            return;
        }

        $context->reply('info.header', ['nickname' => $account->getNickname()]);

        $statusKey = match ($account->getStatus()) {
            NickStatus::Registered => 'info.status_registered',
            NickStatus::Suspended => 'info.status_suspended',
            default => 'info.status_registered',
        };
        $context->reply('info.status', ['status' => $context->trans($statusKey)]);

        $context->reply('info.registered_at', [
            'date' => $account->getRegisteredAt()?->format('d/m/Y H:i') ?? '—',
        ]);

        // Last seen — show "now online" only when the user is identified (+r).
        // Unauthenticated users must not be shown as connected (privacy / IRCd rules).
        try {
            $onlineUser = $this->userRepository->findByNick(new Nick($account->getNickname()));
        } catch (InvalidArgumentException) {
            $onlineUser = null;
        }

        if (null !== $onlineUser && $onlineUser->isIdentified()) {
            $context->reply('info.last_seen_online');
        } elseif (null !== $account->getLastSeenAt()) {
            $context->reply('info.last_seen_at', [
                'date' => $account->getLastSeenAt()->format('d/m/Y H:i'),
            ]);
        } else {
            $context->reply('info.last_seen_never');
        }

        // Last quit message
        if (null !== $account->getLastQuitMessage()) {
            $context->reply('info.last_quit', ['message' => $account->getLastQuitMessage()]);
        }

        if ($isOwnerIdentified && null !== $account->getEmail()) {
            $context->reply('info.email', ['email' => $account->getEmail()]);
        }

        if ($isOwnerIdentified && null !== $account->getVhost() && '' !== $account->getVhost()) {
            $context->reply('info.vhost', ['vhost' => $account->getVhost()]);
        }

        $context->reply('info.footer');
    }
}
