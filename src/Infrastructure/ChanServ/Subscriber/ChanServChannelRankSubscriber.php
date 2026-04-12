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
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
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
 *
 * MessageReceivedEvent/IrcMessageProcessedEvent usage (NOT a violation of AGENTS.md §3.1.4):
 * This is NOT routing PRIVMSG to ChanServ (that's ServiceCommandGateway's job). Instead, this
 * is a batch/transaction pattern: snapshot pending channels at message start (priority 256),
 * process them at end (priority -255). This prevents multiple MODE commands when multiple
 * domain events (ChannelFounderChangedEvent, ChannelSecureEnabledEvent) fire in one cycle.
 * Same pattern as DoctrineIdentityMapClearSubscriber for memory management.
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
            ChannelSyncedEvent::class => ['onChannelSynced', -5],
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
            if (null !== $channel && !$channel->isSuspended() && !$channel->isForbidden()) {
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

        if ($channel->isSuspended() || $channel->isForbidden()) {
            return;
        }

        $params = $event->modeParams;
        $paramIdx = 0;
        $adding = true;

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

            $memberCtx = $this->resolveMemberContext($channel, $targetId);
            if (null === $memberCtx) {
                continue;
            }

            $letterRank = self::RANK_ORDER[$letter] ?? 0;
            $desired = $memberCtx['desired'];
            $sender = $memberCtx['sender'];
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

        if ($channel->isSuspended() || $channel->isForbidden()) {
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

        if ($channel->isSuspended() || $channel->isForbidden()) {
            return;
        }

        $memberCtx = $this->resolveMemberContext($channel, $uid);
        if (null === $memberCtx) {
            return;
        }

        $sender = $memberCtx['sender'];
        $desired = $memberCtx['desired'];
        $currentLetter = $this->roleToLetter($event->role);
        $hasRole = ChannelMemberRole::None !== $event->role;

        if ($this->applySecureStripOnJoin($channel, $channelName, $uid, $currentLetter, $desired, $sender, $hasRole)) {
            return;
        }

        if ('' === $desired) {
            return;
        }

        if (!$sender->isIdentified) {
            return;
        }

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

        if ($channel->isSuspended() || $channel->isForbidden()) {
            return;
        }

        $memberCtx = $this->resolveMemberContext($channel, $uid);
        if (null === $memberCtx) {
            return;
        }

        $sender = $memberCtx['sender'];
        $desired = $memberCtx['desired'];

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

        if ($channel->isSuspended() || $channel->isForbidden()) {
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
            if ($channel->isSuspended() || $channel->isForbidden()) {
                continue;
            }
            $this->syncRanksForChannel($channel);
        }
    }

    /**
     * On ChannelSyncedEvent: sync ranks for this channel (handles new channels from SJOIN).
     * During burst, bots are not introduced yet, so this runs after EOS via NetworkSyncCompleteEvent.
     * For channels created after burst via SJOIN, this handles SECURE strip for users who joined
     * with the channel (no UserJoinedChannelEvent dispatched for new channels).
     */
    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($event->channel->name->value));
        if (null === $channel) {
            return;
        }

        if ($channel->isSuspended() || $channel->isForbidden()) {
            return;
        }

        $this->syncRanksForChannel($channel);
    }

    /**
     * Resolves sender (SenderView) and desired prefix letter for a UID in a channel.
     *
     * @return array{sender: SenderView, desired: string}|null
     */
    private function resolveMemberContext(RegisteredChannel $channel, string $uid): ?array
    {
        $sender = $this->userLookup->findByUid($uid) ?? $this->userLookup->findByNick($uid);
        if (null === $sender) {
            return null;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $account = $this->nickRepository->findByNick($sender->nick);
        $desired = null !== $account
            ? $this->accessHelper->getDesiredPrefixLetter($channel, $account->getId(), $modeSupport)
            : '';

        return ['sender' => $sender, 'desired' => $desired];
    }

    /**
     * Applies SECURE strip on join: strip rank above desired, or strip all if no access and has role.
     * Returns true if caller should return (no access and had role stripped).
     */
    private function applySecureStripOnJoin(
        RegisteredChannel $channel,
        string $channelName,
        string $uid,
        string $currentLetter,
        string $desired,
        SenderView $sender,
        bool $hasRole,
    ): bool {
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

        if ($channel->isSecure() && '' === $desired && $hasRole) {
            if ('' !== $currentLetter) {
                $this->channelServiceActions->setChannelMemberMode($channelName, $uid, $currentLetter, false);
                $this->logger->debug('ChanServ SECURE strip on join', [
                    'channel' => $channelName,
                    'uid' => $uid,
                    'mode' => '-' . $currentLetter,
                ]);
            }

            return true;
        }

        return false;
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

            $memberOps = $this->syncSingleMember($channel, $channelName, $uid, $member, $modeSupport);
            foreach ($memberOps as $op) {
                $ops[] = $op;
            }
        }

        $this->flushMemberModeBatch($channelName, $ops);
    }

    /**
     * Computes mode operations for a single member based on current and desired rank.
     *
     * @return list<array{uid: string, letter: string, add: bool}>
     */
    private function syncSingleMember(
        RegisteredChannel $channel,
        string $channelName,
        string $uid,
        array $member,
        ChannelModeSupportInterface $modeSupport,
    ): array {
        $memberCtx = $this->resolveMemberContext($channel, $uid);
        if (null === $memberCtx) {
            return [];
        }

        $sender = $memberCtx['sender'];
        $desired = $memberCtx['desired'];
        $currentLetter = $member['roleLetter'] ?? '';

        $effectiveDesired = $sender->isIdentified ? $desired : '';
        $currentRank = self::RANK_ORDER[$currentLetter] ?? 0;
        $desiredRank = self::RANK_ORDER[$effectiveDesired] ?? 0;

        if ($currentRank > $desiredRank) {
            return $this->collectOpsWhenRankAboveDesired($channelName, $uid, $member, $currentLetter, $effectiveDesired, $desiredRank, $modeSupport);
        }

        // @codeCoverageIgnoreStart
        // NOTE: Unreachable defensive code - see original method for analysis.
        if ($channel->isSecure() && '' === $effectiveDesired && '' !== $currentLetter) {
            return $this->collectOpsForSecureStrip($channelName, $uid, $member, $currentLetter, $modeSupport);
        }
        // @codeCoverageIgnoreEnd

        if ('' === $effectiveDesired || !$this->shouldSetMode($currentLetter, $effectiveDesired)) {
            return [];
        }

        $this->logger->debug('ChanServ auto-rank on sync', [
            'channel' => $channelName,
            'uid' => $uid,
            'mode' => '+' . $effectiveDesired,
        ]);

        return [['uid' => $uid, 'letter' => $effectiveDesired, 'add' => true]];
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
        // @codeCoverageIgnoreStart
        // Unreachable defensive code: when currentLetter is empty, currentRank=0.
        // This method is only called when currentRank > desiredRank, which can't be true if currentRank=0.
        if ('' === $currentLetter) {
            $hasLetters = [];
        }
        // @codeCoverageIgnoreEnd
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
     *
     * @codeCoverageIgnore
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
