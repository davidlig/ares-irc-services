<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;

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
        private readonly NetworkUserLookupPort $userLookup,
        private readonly VhostDisplayResolver $vhostDisplayResolver,
        private readonly ChannelAccessRepositoryInterface $accessRepository,
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
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

    public function getHelpParams(): array
    {
        return [];
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

        if ($isOwnerIdentified) {
            $this->replyUserChannels($context, $account->getId());
        }

        $context->reply('info.footer');
    }

    private function replyUserChannels(NickServContext $context, int $nickId): void
    {
        $accessEntries = $this->accessRepository->findByNick($nickId);
        $founderChannels = $this->channelRepository->findByFounderNickId($nickId);
        $successorChannels = $this->channelRepository->findBySuccessorNickId($nickId);

        if (empty($accessEntries) && empty($founderChannels) && empty($successorChannels)) {
            return;
        }

        $channels = [];

        foreach ($founderChannels as $channel) {
            $channels[$channel->getId()] = [
                'name' => $channel->getName(),
                'type' => 'founder',
            ];
        }

        foreach ($successorChannels as $channel) {
            if (!isset($channels[$channel->getId()])) {
                $channels[$channel->getId()] = [
                    'name' => $channel->getName(),
                    'type' => 'successor',
                ];
            }
        }

        $accessChannelIds = [];
        foreach ($accessEntries as $access) {
            if (!isset($channels[$access->getChannelId()])) {
                $accessChannelIds[] = $access->getChannelId();
                $channels[$access->getChannelId()] = [
                    'name' => '',
                    'type' => 'access',
                    'level' => $access->getLevel(),
                ];
            }
        }

        if (!empty($accessChannelIds)) {
            $channelEntities = $this->channelRepository->findByIds($accessChannelIds);
            foreach ($channelEntities as $channel) {
                if (isset($channels[$channel->getId()])) {
                    $channels[$channel->getId()]['name'] = $channel->getName();
                }
            }
        }

        $context->reply('info.channels_header');

        foreach ($channels as $channelData) {
            if ('access' === $channelData['type']) {
                $context->reply('info.channels_entry_access', [
                    'channel' => $channelData['name'],
                    'level' => $channelData['level'],
                ]);
            } elseif ('founder' === $channelData['type']) {
                $context->reply('info.channels_entry_founder', [
                    'channel' => $channelData['name'],
                ]);
            } elseif ('successor' === $channelData['type']) {
                $context->reply('info.channels_entry_successor', [
                    'channel' => $channelData['name'],
                ]);
            }
        }
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

        if ($account->isSuspended()) {
            if (null !== $account->getSuspendedUntil()) {
                $context->reply('info.suspended_until', ['date' => $context->formatDate($account->getSuspendedUntil())]);
            } else {
                $context->reply('info.suspended_permanent');
            }
        }

        $context->reply('info.registered_at', [
            'date' => $context->formatDate($account->getRegisteredAt()),
        ]);

        $this->replyLastSeen($context, $account);

        if (null !== $account->getLastQuitMessage()) {
            $context->reply('info.last_quit', ['message' => $account->getLastQuitMessage()]);
        }

        if ($isOwnerIdentified && null !== $account->getEmail()) {
            $context->reply('info.email', ['email' => $account->getEmail()]);
        }

        $displayVhost = $this->vhostDisplayResolver->getDisplayVhost($account->getVhost());
        if ('' !== $displayVhost) {
            $context->reply('info.vhost', ['vhost' => $displayVhost]);
        }
    }

    private function replyLastSeen(NickServContext $context, RegisteredNick $account): void
    {
        $onlineUser = $this->userLookup->findByNick($account->getNickname());

        if (null !== $onlineUser && $onlineUser->isIdentified) {
            $context->reply('info.last_seen_online');
        } elseif (null !== $account->getLastSeenAt()) {
            $context->reply('info.last_seen_at', [
                'date' => $context->formatDate($account->getLastSeenAt()),
            ]);
        } else {
            $context->reply('info.last_seen_never');
        }
    }

    private function isSenderOwner(?SenderView $sender, RegisteredNick $account): bool
    {
        return null !== $sender
            && 0 === strcasecmp($sender->nick, $account->getNickname());
    }

    private function isSenderOwnerIdentified(?SenderView $sender, RegisteredNick $account): bool
    {
        return $this->isSenderOwner($sender, $account) && null !== $sender && $sender->isIdentified;
    }

    private function getStatusTranslationKey(RegisteredNick $account): string
    {
        return match ($account->getStatus()) {
            NickStatus::Registered => 'info.status_registered',
            NickStatus::Suspended => 'info.status_suspended',
            default => 'info.status_registered',
        };
    }
}
