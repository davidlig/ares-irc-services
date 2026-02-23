<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeInterface;
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
        $targetNick = $context->args[0];
        $account = $this->nickRepository->findByNick($targetNick);

        if (null === $account || $account->isPending()) {
            $context->reply('info.not_registered', ['nickname' => $targetNick]);

            return;
        }

        if ($account->isForbidden()) {
            $this->replyForbidden($context, $account);

            return;
        }

        $isOwnerIdentified = $this->isSenderOwnerIdentified($context->sender, $account);
        if ($account->isPrivate() && !$this->isSenderOwner($context->sender, $account)) {
            $context->reply('info.private', ['nickname' => $account->getNickname()]);

            return;
        }

        $this->replyAccountInfo($context, $account, $isOwnerIdentified);
    }

    private function replyForbidden(NickServContext $context, RegisteredNick $account): void
    {
        $context->reply('info.header', ['nickname' => $account->getNickname()]);
        $context->reply('info.status', ['status' => $context->trans('info.status_forbidden')]);
        if (null !== $account->getReason()) {
            $context->reply('info.reason', ['reason' => $account->getReason()]);
        }
        $context->reply('info.footer');
    }

    private function replyAccountInfo(
        NickServContext $context,
        RegisteredNick $account,
        bool $isOwnerIdentified,
    ): void {
        $context->reply('info.header', ['nickname' => $account->getNickname()]);

        $statusKey = $this->getStatusTranslationKey($account);
        $context->reply('info.status', ['status' => $context->trans($statusKey)]);

        if ($account->isSuspended() && null !== $account->getReason()) {
            $context->reply('info.reason', ['reason' => $account->getReason()]);
        }

        $context->reply('info.registered_at', [
            'date' => $this->formatInfoDate($account->getRegisteredAt()),
        ]);

        $this->replyLastSeen($context, $account);

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

    private function replyLastSeen(NickServContext $context, RegisteredNick $account): void
    {
        $onlineUser = $this->findOnlineUser($account->getNickname());

        if (null !== $onlineUser && $onlineUser->isIdentified()) {
            $context->reply('info.last_seen_online');
        } elseif (null !== $account->getLastSeenAt()) {
            $context->reply('info.last_seen_at', [
                'date' => $this->formatInfoDate($account->getLastSeenAt()),
            ]);
        } else {
            $context->reply('info.last_seen_never');
        }
    }

    private function findOnlineUser(string $nickname): ?NetworkUser
    {
        try {
            return $this->userRepository->findByNick(new Nick($nickname));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function isSenderOwner(?NetworkUser $sender, RegisteredNick $account): bool
    {
        return null !== $sender
            && 0 === strcasecmp($sender->getNick()->value, $account->getNickname());
    }

    private function isSenderOwnerIdentified(?NetworkUser $sender, RegisteredNick $account): bool
    {
        return $this->isSenderOwner($sender, $account) && null !== $sender && $sender->isIdentified();
    }

    private function getStatusTranslationKey(RegisteredNick $account): string
    {
        return match ($account->getStatus()) {
            NickStatus::Registered => 'info.status_registered',
            NickStatus::Suspended => 'info.status_suspended',
            default => 'info.status_registered',
        };
    }

    private function formatInfoDate(?DateTimeInterface $date): string
    {
        return null !== $date ? $date->format('d/m/Y H:i') : '—';
    }
}
