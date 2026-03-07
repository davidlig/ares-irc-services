<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Event\ChannelFounderChangedEvent;
use App\Application\ChanServ\Event\ChannelSecureEnabledEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\ChannelRankSyncPendingRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function in_array;

/**
 * Applies ChanServ ranks on registered channels: founder gets +q (or highest supported),
 * others get +a/+o/+h/+v based on AUTO* levels. We run syncRanksForChannel only after EOS
 * (NetworkSyncCompleteEvent) for all registered channels — during BURST the bots are not
 * introduced yet so MODE cannot be sent. After EOS we sync all channels (SECURE on or off);
 * SECURE-only stripping still applies inside syncRanksForChannel. On founder change we sync that channel.
 */
final readonly class ChanServChannelRankSubscriber implements EventSubscriberInterface
{
    /** Rank order for comparison (higher = more privilege). */
    private const array RANK_ORDER = ['q' => 5, 'a' => 4, 'o' => 3, 'h' => 2, 'v' => 1];

    /** Prefix letters from highest to lowest; used to strip all ranks above desired in one go. */
    private const array PREFIX_LETTERS_DESC = ['q', 'a', 'o', 'h', 'v'];

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
        private ChannelRankSyncPendingRegistry $syncPendingRegistry,
        private string $chanservUid,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessageReceived', 256],
            IrcMessageProcessedEvent::class => ['onIrcMessageProcessed', -255],
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
            UserLeftChannelEvent::class => ['onUserLeftChannel', 0],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 0],
            ChannelSecureEnabledEvent::class => ['onChannelSecureEnabled', 0],
            ChannelFounderChangedEvent::class => ['onChannelFounderChanged', 0],
            ModeReceivedEvent::class => ['onModeReceived', 255],
        ];
    }

    public function onMessageReceived(): void
    {
        $this->syncPendingRegistry->snapshotPendingAtStart();
    }

    public function onIrcMessageProcessed(): void
    {
        foreach ($this->syncPendingRegistry->getPendingAtStart() as $channelName) {
            $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
            if (null !== $channel) {
                $this->syncRanksForChannel($channel);
            }
            $this->syncPendingRegistry->remove($channelName);
        }
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

        $this->syncPendingRegistry->add($channel->getName());
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

        $currentLetter = $this->roleToLetter($event->role);

        if ($channel->isSecure() && '' !== $currentLetter) {
            $currentRank = self::RANK_ORDER[$currentLetter] ?? 0;
            $desiredRank = self::RANK_ORDER[$desired] ?? 0;
            if ($currentRank > $desiredRank) {
                $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $currentLetter, false);
                $this->logger->debug('ChanServ SECURE strip on join (rank above access)', [
                    'channel' => $channelName,
                    'uid' => $uid,
                    'mode' => '-' . $currentLetter,
                ]);
            }
        }

        if ($channel->isSecure() && '' === $desired && ChannelMemberRole::None !== $event->role) {
            if ('' !== $currentLetter) {
                $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $currentLetter, false);
                $this->logger->debug('ChanServ SECURE strip on join', [
                    'channel' => $channelName,
                    'uid' => $uid,
                    'mode' => '-' . $currentLetter,
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

        // Update channel last used when an identified user with ACCESS joins
        $channel->touchLastUsed();
        $this->channelRepository->save($channel);

        $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $desired, true);
        $this->logger->debug('ChanServ auto-rank on join', [
            'channel' => $channelName,
            'uid' => $uid,
            'nick' => $sender->nick,
            'mode' => '+' . $desired,
        ]);
    }

    /**
     * Updates channel last used when an identified user with ACCESS leaves (PART, KICK or QUIT).
     */
    public function onUserLeftChannel(UserLeftChannelEvent $event): void
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

        if ('' === $desired || !$sender->isIdentified) {
            return;
        }

        $channel->touchLastUsed();
        $this->channelRepository->save($channel);
        $this->logger->debug('ChanServ last used on leave', [
            'channel' => $channelName,
            'uid' => $uid,
            'nick' => $sender->nick,
        ]);
    }

    public function onChannelFounderChanged(ChannelFounderChangedEvent $event): void
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($event->channelName));
        if (null === $channel) {
            return;
        }

        $this->syncPendingRegistry->add($channel->getName());
    }

    /**
     * After EOS: sync ranks for all registered channels (bots are introduced by then).
     * SECURE-only stripping still applies inside syncRanksForChannel when channel has SECURE.
     */
    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $channels = $this->channelRepository->listAll();
        foreach ($channels as $channel) {
            $this->syncRanksForChannel($channel);
        }
    }

    /**
     * Re-syncs user modes (q, a, o, h, v) for one channel based on current founder and access list.
     * Used after network sync and after founder change.
     * Batches all mode changes into a single MODE line (commit-style) per channel.
     */
    private function syncRanksForChannel(RegisteredChannel $channel): void
    {
        $channelName = $channel->getName();
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $ops = [];

        foreach ($view->members as $member) {
            $uid = $member['uid'] ?? '';
            if ('' === $uid || $this->chanservUid === $uid) {
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

            $currentRank = self::RANK_ORDER[$currentLetter] ?? 0;
            $desiredRank = self::RANK_ORDER[$effectiveDesired] ?? 0;

            // Sync: strip only the prefix letters the user actually has (from SJOIN) that are above desired
            if ($currentRank > $desiredRank) {
                foreach ($this->collectOpsWhenRankAboveDesired($channelName, $uid, $member, $currentLetter, $effectiveDesired, $desiredRank, $modeSupport) as $op) {
                    $ops[] = $op;
                }
                continue;
            }

            // Strip only the prefix letters the user actually has when no access (SECURE)
            if ($channel->isSecure() && '' === $effectiveDesired && '' !== $currentLetter) {
                foreach ($this->collectOpsForSecureStrip($channelName, $uid, $member, $currentLetter, $modeSupport) as $op) {
                    $ops[] = $op;
                }
                continue;
            }

            if ('' === $effectiveDesired || !$this->shouldSetMode($currentLetter, $effectiveDesired)) {
                continue;
            }

            $ops[] = ['uid' => $uid, 'letter' => $effectiveDesired, 'add' => true];
            $this->logger->debug('ChanServ auto-rank on sync', [
                'channel' => $channelName,
                'uid' => $uid,
                'mode' => '+' . $effectiveDesired,
            ]);
        }

        $this->flushMemberModeBatch($channelName, $ops);
    }

    /**
     * @return list<array{uid: string, letter: string, add: bool}>
     */
    private function collectOpsWhenRankAboveDesired(
        string $channelName,
        string $uid,
        array $member,
        string $currentLetter,
        string $effectiveDesired,
        int $desiredRank,
        ChannelModeSupportInterface $modeSupport,
    ): array {
        $ops = [];
        $supported = $modeSupport->getSupportedPrefixModes();
        $hasLetters = $member['prefixLetters'] ?? [$currentLetter];
        if ('' === $currentLetter) {
            $hasLetters = [];
        }
        foreach (self::PREFIX_LETTERS_DESC as $letter) {
            if (!in_array($letter, $supported, true) || !in_array($letter, $hasLetters, true)) {
                continue;
            }
            $letterRank = self::RANK_ORDER[$letter] ?? 0;
            if ($letterRank <= $desiredRank) {
                continue;
            }
            $ops[] = ['uid' => $uid, 'letter' => $letter, 'add' => false];
            $this->logger->debug('ChanServ sync strip (rank above desired)', [
                'channel' => $channelName,
                'uid' => $uid,
                'mode' => '-' . $letter,
            ]);
        }
        if ('' !== $effectiveDesired) {
            $ops[] = ['uid' => $uid, 'letter' => $effectiveDesired, 'add' => true];
            $this->logger->debug('ChanServ auto-rank on sync', [
                'channel' => $channelName,
                'uid' => $uid,
                'mode' => '+' . $effectiveDesired,
            ]);
        }

        return $ops;
    }

    /**
     * @return list<array{uid: string, letter: string, add: bool}>
     */
    private function collectOpsForSecureStrip(
        string $channelName,
        string $uid,
        array $member,
        string $currentLetter,
        ChannelModeSupportInterface $modeSupport,
    ): array {
        $ops = [];
        $supported = $modeSupport->getSupportedPrefixModes();
        $hasLetters = $member['prefixLetters'] ?? [$currentLetter];
        foreach (self::PREFIX_LETTERS_DESC as $letter) {
            if (!in_array($letter, $supported, true) || !in_array($letter, $hasLetters, true)) {
                continue;
            }
            $ops[] = ['uid' => $uid, 'letter' => $letter, 'add' => false];
            $this->logger->debug('ChanServ SECURE strip on sync', [
                'channel' => $channelName,
                'uid' => $uid,
                'mode' => '-' . $letter,
            ]);
        }

        return $ops;
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

    /**
     * Sends member prefix mode changes in batches of up to 6 operations per MODE line
     * (e.g. +oooooo nick1 nick2 nick3 nick4 nick5 nick6 or +oo-vv nick1 nick2 nick1 nick2).
     * Each op: ['uid' => string, 'letter' => string, 'add' => bool].
     *
     * @param array<int, array{uid: string, letter: string, add: bool}> $operations
     */
    private function flushMemberModeBatch(string $channelName, array $operations): void
    {
        if ([] === $operations) {
            return;
        }

        $chunks = array_chunk($operations, 6);

        foreach ($chunks as $chunk) {
            $modeStr = '';
            $params = [];
            $currentSign = '';

            foreach ($chunk as $op) {
                $sign = $op['add'] ? '+' : '-';
                if ($currentSign !== $sign) {
                    $modeStr .= $sign;
                    $currentSign = $sign;
                }
                $modeStr .= $op['letter'];
                $params[] = $op['uid'];
            }

            $this->channelServiceActions->setChannelModes($channelName, $modeStr, $params);
        }
    }
}
