<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Enforces AKICK on user join and after burst completion:
 * - On JOIN: matches user mask against channel's AKICK list, sets +b and kicks matching users.
 * - On NetworkSyncCompleteEvent: enforces AKICKs on all users currently in registered channels.
 *
 * Runs after ChannelSyncedEvent so channel state is available.
 */
final readonly class ChanServAkickEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelAkickRepositoryInterface $akickRepository,
        private ChannelLookupPort $channelLookup,
        private NetworkUserLookupPort $userLookup,
        private ChannelServiceActionsPort $channelServiceActions,
        private string $chanservNick,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoined', 0],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 0],
        ];
    }

    public function onUserJoined(UserJoinedChannelEvent $event): void
    {
        $channelName = $event->channel->value;
        $uid = $event->uid->value;

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            return;
        }

        $user = $this->userLookup->findByUid($uid);
        if (null === $user) {
            return;
        }

        if ($user->isOper) {
            return;
        }

        $userMask = $user->nick . '!' . $user->ident . '@' . $user->hostname;

        $akicks = $this->akickRepository->listByChannel($channel->getId());

        foreach ($akicks as $akick) {
            if ($akick->isExpired()) {
                continue;
            }

            if ($akick->matches($userMask)) {
                $this->enforceAkick($channelName, $akick->getMask(), $uid, $akick->getReason());
                break;
            }
        }
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $channels = $this->channelRepository->listAll();

        foreach ($channels as $channel) {
            $channelName = $channel->getName();
            $view = $this->channelLookup->findByChannelName($channelName);

            if (null === $view) {
                continue;
            }

            $this->enforceAkicksForChannel($channel->getId(), $channelName, $view);
        }
    }

    private function enforceAkicksForChannel(int $channelId, string $channelName, ChannelView $view): void
    {
        $akicks = $this->akickRepository->listByChannel($channelId);

        if ([] === $akicks) {
            return;
        }

        $validAkicks = [];
        foreach ($akicks as $akick) {
            if (!$akick->isExpired()) {
                $validAkicks[] = $akick;
            }
        }

        if ([] === $validAkicks) {
            return;
        }

        foreach ($view->members as $member) {
            $uid = $member['uid'];
            $user = $this->userLookup->findByUid($uid);

            if (null === $user || $user->isOper) {
                continue;
            }

            $userMask = $user->nick . '!' . $user->ident . '@' . $user->hostname;

            foreach ($validAkicks as $akick) {
                if ($akick->matches($userMask)) {
                    $this->enforceAkick($channelName, $akick->getMask(), $uid, $akick->getReason());
                    break;
                }
            }
        }
    }

    private function enforceAkick(string $channelName, string $mask, string $uid, ?string $reason): void
    {
        $kickReason = $reason ?? 'AKICK: ' . $mask;

        $this->channelServiceActions->setChannelModes($channelName, '+b', [$mask]);
        $this->channelServiceActions->kickFromChannel($channelName, $uid, $kickReason);

        $this->logger->info('AKICK enforced', [
            'channel' => $channelName,
            'mask' => $mask,
            'uid' => $uid,
        ]);
    }
}
