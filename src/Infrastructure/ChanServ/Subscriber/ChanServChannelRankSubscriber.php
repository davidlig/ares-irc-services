<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Event\ChannelSecureEnabledEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function in_array;

/**
 * Applies ChanServ ranks on registered channels: founder gets +q (or highest supported),
 * others get +a/+o/+h/+v based on AUTO* levels. When SECURE is on, strips modes from
 * users without access. Runs on user join, after sync, and when SECURE is enabled.
 */
final readonly class ChanServChannelRankSubscriber implements EventSubscriberInterface
{
    /** Rank order for comparison (higher = more privilege). */
    private const array RANK_ORDER = ['q' => 5, 'a' => 4, 'o' => 3, 'h' => 2, 'v' => 1];

    /** Prefix mode letters (v, h, o, a, q) that SECURE can strip. */
    private const array PREFIX_LETTERS = ['v', 'h', 'o', 'a', 'q'];

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private NetworkUserLookupPort $userLookup,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ChannelLookupPort $channelLookup,
        private ChannelServiceActionsPort $channelServiceActions,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChanServAccessHelper $accessHelper,
        private string $chanservUid,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 0],
            ChannelSyncedEvent::class => ['onChannelSyncedSecureStrip', 0],
            ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
            ModeReceivedEvent::class => ['onModeReceived', 255],
        ];
    }

    public function onModeReceived(ModeReceivedEvent $event): void
    {
        $channelName = $event->channelName->value;
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel || !$channel->isSecure()) {
            return;
        }

        $params = $event->modeParams;
        $paramIdx = 0;
        $adding = true;
        $modeSupport = $this->modeSupportProvider->getSupport();

        foreach (str_split($event->modeStr) as $char) {
            if ('+' === $char) {
                $adding = true;
                continue;
            }
            if ('-' === $char) {
                $adding = false;
                continue;
            }

            if (!in_array($char, self::PREFIX_LETTERS, true)) {
                if (in_array($char, ['b', 'e', 'I'], true) && $paramIdx < count($params)) {
                    ++$paramIdx;
                }
                continue;
            }
            $letter = $char;

            if ($paramIdx >= count($params)) {
                break;
            }

            $targetId = $params[$paramIdx];
            ++$paramIdx;

            if (!$adding) {
                continue;
            }

            $sender = $this->userLookup->findByUid($targetId) ?? $this->userLookup->findByNick($targetId);
            if (null === $sender) {
                continue;
            }

            $account = $this->nickRepository->findByNick($sender->nick);
            $desired = null !== $account
                ? $this->accessHelper->getDesiredPrefixLetter($channel, $account->getId(), $modeSupport)
                : '';

            $letterRank = self::RANK_ORDER[$letter] ?? 0;
            $desiredRank = self::RANK_ORDER[$desired] ?? 0;
            if ($letterRank <= $desiredRank) {
                continue;
            }

            $this->channelServiceActions->setChannelMemberMode($channelName, $sender->uid, $letter, false);
            $this->logger->debug('ChanServ SECURE strip on MODE', [
                'channel' => $channelName,
                'uid' => $sender->uid,
                'mode' => '-' . $letter,
            ]);
        }
    }

    public function onChannelSecureEnabled(ChannelSecureEnabledEvent $event): void
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($event->channelName));
        if (null === $channel || !$channel->isSecure()) {
            return;
        }

        $this->stripUsersWithoutAccessInChannel($channel);
    }

    /**
     * Runs after +r (priority 0): if SECURE is on, strip ranks that do not correspond for this channel.
     */
    public function onChannelSyncedSecureStrip(ChannelSyncedEvent $event): void
    {
        $channelName = $event->channel->name->value;
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel || !$channel->isSecure()) {
            return;
        }

        $this->stripUsersWithoutAccessInChannel($channel);
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $channelName = $event->channel->value;
        $uid = $event->uid->value;

        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel) {
            return;
        }

        $sender = $this->userLookup->findByUid($uid);
        if (null === $sender) {
            return;
        }

        $account = $this->nickRepository->findByNick($sender->nick);
        $modeSupport = $this->modeSupportProvider->getSupport();
        $desired = null !== $account
            ? $this->accessHelper->getDesiredPrefixLetter($channel, $account->getId(), $modeSupport)
            : '';

        if ($channel->isSecure() && '' === $desired && ChannelMemberRole::None !== $event->role) {
            $letter = $this->roleToLetter($event->role);
            if ('' !== $letter) {
                $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $letter, false);
                $this->logger->debug('ChanServ SECURE strip on join', [
                    'channel' => $channelName,
                    'uid' => $uid,
                    'mode' => '-' . $letter,
                ]);
            }

            return;
        }

        if ('' === $desired) {
            return;
        }

        // Do not grant any rank to unauthenticated users (e.g. guest nicks after nick protection rename)
        if (!$sender->isIdentified) {
            return;
        }

        $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $desired, true);
        $this->logger->debug('ChanServ auto-rank on join', [
            'channel' => $channelName,
            'uid' => $uid,
            'nick' => $sender->nick,
            'mode' => '+' . $desired,
        ]);
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $modeSupport = $this->modeSupportProvider->getSupport();
        $channels = $this->channelRepository->listAll();

        foreach ($channels as $channel) {
            $channelName = $channel->getName();
            $view = $this->channelLookup->findByChannelName($channelName);
            if (null === $view) {
                continue;
            }

            foreach ($view->members as $member) {
                $uid = $member['uid'] ?? '';
                if ('' === $uid || $uid === $this->chanservUid) {
                    continue;
                }

                $sender = $this->userLookup->findByUid($uid);
                if (null === $sender) {
                    continue;
                }

                $account = $this->nickRepository->findByNick($sender->nick);
                $desired = null !== $account
                    ? $this->accessHelper->getDesiredPrefixLetter($channel, $account->getId(), $modeSupport)
                    : '';

                $currentLetter = $member['roleLetter'] ?? '';

                // Unauthenticated users must not keep any ChanServ-managed rank (e.g. guest nick after rename)
                $effectiveDesired = $sender->isIdentified ? $desired : '';

                if ($channel->isSecure() && '' === $effectiveDesired && '' !== $currentLetter) {
                    $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $currentLetter, false);
                    $this->logger->debug('ChanServ SECURE strip on sync', [
                        'channel' => $channelName,
                        'uid' => $uid,
                        'mode' => '-' . $currentLetter,
                    ]);
                    continue;
                }

                if ('' === $effectiveDesired || !$this->shouldSetMode($currentLetter, $effectiveDesired)) {
                    continue;
                }

                $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $effectiveDesired, true);
                $this->logger->debug('ChanServ auto-rank on sync', [
                    'channel' => $channelName,
                    'uid' => $uid,
                    'mode' => '+' . $effectiveDesired,
                ]);
            }
        }
    }

    private function shouldSetMode(string $currentLetter, string $desiredLetter): bool
    {
        $currentRank = self::RANK_ORDER[$currentLetter] ?? 0;
        $desiredRank = self::RANK_ORDER[$desiredLetter] ?? 0;

        return $desiredRank > $currentRank;
    }

    private function roleToLetter(ChannelMemberRole $role): string
    {
        return match ($role) {
            ChannelMemberRole::Owner => 'q',
            ChannelMemberRole::Admin => 'a',
            ChannelMemberRole::Op => 'o',
            ChannelMemberRole::HalfOp => 'h',
            ChannelMemberRole::Voice => 'v',
            ChannelMemberRole::None => '',
        };
    }

    private function stripUsersWithoutAccessInChannel(RegisteredChannel $channel): void
    {
        $channelName = $channel->getName();
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();

        foreach ($view->members as $member) {
            $uid = $member['uid'] ?? '';
            if ('' === $uid || $uid === $this->chanservUid) {
                continue;
            }

            $currentLetter = $member['roleLetter'] ?? '';
            if ('' === $currentLetter) {
                continue;
            }

            $sender = $this->userLookup->findByUid($uid);
            if (null === $sender) {
                continue;
            }

            $account = $this->nickRepository->findByNick($sender->nick);
            $desired = null !== $account
                ? $this->accessHelper->getDesiredPrefixLetter($channel, $account->getId(), $modeSupport)
                : '';
            $effectiveDesired = $sender->isIdentified ? $desired : '';

            if ('' !== $effectiveDesired) {
                continue;
            }

            $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $currentLetter, false);
            $this->logger->debug('ChanServ SECURE strip (SECURE enabled)', [
                'channel' => $channelName,
                'uid' => $uid,
                'mode' => '-' . $currentLetter,
            ]);
        }
    }
}
