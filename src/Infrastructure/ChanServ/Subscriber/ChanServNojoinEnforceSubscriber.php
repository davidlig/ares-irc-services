<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Enforces NOJOIN level on user join and after burst completion:
 * - On JOIN: if user's access level < NOJOIN level, kick them from the channel.
 * - On NetworkSyncCompleteEvent: enforces NOJOIN on all users currently in registered channels.
 *
 * NOJOIN value -1 means disabled (no one is kicked).
 * Oper users are exempt from NOJOIN enforcement.
 *
 * Runs BEFORE ChanServChannelRankSubscriber (priority 10 vs 0) to prevent
 * applying auto-ranks to users who will be kicked.
 */
final readonly class ChanServNojoinEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ChannelLookupPort $channelLookup,
        private NetworkUserLookupPort $userLookup,
        private ChannelServiceActionsPort $channelServiceActions,
        private ChanServAccessHelper $accessHelper,
        private TranslatorInterface $translator,
        private string $chanservNick,
        private string $defaultLanguage = 'en',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoined', 10],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 10],
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

        if ($channel->isSuspended() || $channel->isForbidden()) {
            return;
        }

        $user = $this->userLookup->findByUid($uid);
        if (null === $user) {
            return;
        }

        if ($user->isOper) {
            return;
        }

        if ($user->nick === $this->chanservNick) {
            return;
        }

        $this->enforceNojoinForUser($channel, $channelName, $uid, $user->nick, $user->isIdentified);
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $channels = $this->channelRepository->listAll();

        foreach ($channels as $channel) {
            if ($channel->isSuspended() || $channel->isForbidden()) {
                continue;
            }

            $channelName = $channel->getName();
            $nojoinLevel = $this->getNojoinLevel($channel->getId());

            if ($nojoinLevel < 0) {
                continue;
            }

            $view = $this->channelLookup->findByChannelName($channelName);
            if (null === $view) {
                continue;
            }

            $this->enforceNojoinForChannel($channel, $view, $nojoinLevel);
        }
    }

    private function enforceNojoinForUser(
        RegisteredChannel $channel,
        string $channelName,
        string $uid,
        string $nick,
        bool $isIdentified,
    ): void {
        $nojoinLevel = $this->getNojoinLevel($channel->getId());

        if ($nojoinLevel < 0) {
            return;
        }

        $registeredNick = $this->nickRepository->findByNick($nick);
        $userLevel = null !== $registeredNick && $isIdentified
            ? $this->accessHelper->effectiveAccessLevel($channel, $registeredNick->getId(), true)
            : ChannelAccess::LEVEL_UNREGISTERED;

        if ($userLevel < $nojoinLevel) {
            $language = null !== $registeredNick
                ? $registeredNick->getLanguage()
                : $this->defaultLanguage;
            $this->kickUser($channelName, $uid, $nick, $userLevel, $nojoinLevel, $language);
        }
    }

    private function enforceNojoinForChannel(
        RegisteredChannel $channel,
        ChannelView $view,
        int $nojoinLevel,
    ): void {
        $channelName = $channel->getName();

        foreach ($view->members as $member) {
            $uid = $member['uid'] ?? '';
            if ('' === $uid) {
                continue;
            }

            $user = $this->userLookup->findByUid($uid);
            if (null === $user || $user->isOper || $user->nick === $this->chanservNick) {
                continue;
            }

            $registeredNick = $this->nickRepository->findByNick($user->nick);
            $userLevel = null !== $registeredNick && $user->isIdentified
                ? $this->accessHelper->effectiveAccessLevel($channel, $registeredNick->getId(), true)
                : ChannelAccess::LEVEL_UNREGISTERED;

            if ($userLevel < $nojoinLevel) {
                $language = null !== $registeredNick
                    ? $registeredNick->getLanguage()
                    : $this->defaultLanguage;
                $this->kickUser($channelName, $uid, $user->nick, $userLevel, $nojoinLevel, $language);
            }
        }
    }

    private function getNojoinLevel(int $channelId): int
    {
        $level = $this->levelRepository->findByChannelAndKey($channelId, ChannelLevel::KEY_NOJOIN);

        return null !== $level ? $level->getValue() : ChannelLevel::getDefault(ChannelLevel::KEY_NOJOIN);
    }

    private function kickUser(string $channelName, string $uid, string $nick, int $userLevel, int $nojoinLevel, string $language): void
    {
        $reason = $this->translator->trans('nojoin.reason', [], 'chanserv', $language);
        $this->channelServiceActions->kickFromChannel($channelName, $uid, $reason);

        $this->logger->info('NOJOIN enforced', [
            'channel' => $channelName,
            'uid' => $uid,
            'nick' => $nick,
            'userLevel' => $userLevel,
            'nojoinLevel' => $nojoinLevel,
        ]);
    }
}
