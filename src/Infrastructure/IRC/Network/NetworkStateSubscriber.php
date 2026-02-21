<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Listens to every incoming IRC message and maintains an in-memory picture of
 * the network state: users (UID / NICK / QUIT) and channels (SJOIN / PART / KICK).
 *
 * Dispatches domain events so that service modules can react to state changes.
 */
class NetworkStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessageReceived', 0],
        ];
    }

    public function onMessageReceived(MessageReceivedEvent $event): void
    {
        $message = $event->message;

        match ($message->command) {
            'UID'    => $this->handleUid($message),
            'NICK'   => $this->handleNick($message),
            'QUIT'   => $this->handleQuit($message),
            'SJOIN'  => $this->handleSjoin($message),
            'PART'   => $this->handlePart($message),
            'KICK'   => $this->handleKick($message),
            'UMODE2' => $this->handleUmode2($message),
            'MD'     => $this->handleMd($message),
            default  => null,
        };
    }

    // -------------------------------------------------------------------------
    // UID — user introduction during burst or new connection
    // Syntax: :SID UID nickname hopcount timestamp username hostname uid
    //                servicestamp umodes virthost cloakedhost ip :gecos
    // -------------------------------------------------------------------------
    private function handleUid(IRCMessage $message): void
    {
        if (count($message->params) < 11) {
            $this->logger->warning('Malformed UID message (not enough params)', [
                'raw' => $message->toRawLine(),
            ]);
            return;
        }

        [, , $timestamp, $username, $hostname, $uidStr, $serviceStamp, $umodes, $virthost, $cloakedHost, $ipBase64]
            = $message->params;

        $nickStr  = $message->params[0];
        $gecos    = $message->trailing ?? '';
        $serverSid = $message->prefix ?? '';

        try {
            $nick  = new Nick($nickStr);
            $uid   = new Uid($uidStr);
            $ident = new Ident($username);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('UID skipped: invalid value — ' . $e->getMessage(), [
                'raw' => $message->toRawLine(),
            ]);
            return;
        }

        $connectedAt = new \DateTimeImmutable('@' . $timestamp);

        $user = new NetworkUser(
            uid:          $uid,
            nick:         $nick,
            ident:        $ident,
            hostname:     $hostname,
            cloakedHost:  $cloakedHost,
            virtualHost:  $virthost,
            modes:        $umodes,
            connectedAt:  $connectedAt,
            realName:     $gecos,
            serverSid:    $serverSid,
            ipBase64:     $ipBase64,
            serviceStamp: (int) $serviceStamp,
        );

        $this->userRepository->add($user);

        $this->logger->info(sprintf(
            'User joined network: %s (%s@%s) [%s]',
            $nick->value,
            $ident->value,
            $user->getDisplayHost(),
            $uid->value,
        ));

        $this->eventDispatcher->dispatch(new UserJoinedNetworkEvent($user));
    }

    // -------------------------------------------------------------------------
    // NICK — nick change
    // Syntax: :uid NICK newnick :timestamp
    // -------------------------------------------------------------------------
    private function handleNick(IRCMessage $message): void
    {
        $newNickStr = $message->params[0] ?? '';
        $sourceId   = $message->prefix ?? '';

        if ('' === $newNickStr || '' === $sourceId) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            $this->logger->warning('NICK received for unknown source: ' . $sourceId);
            return;
        }

        try {
            $newNick = new Nick($newNickStr);
        } catch (\InvalidArgumentException) {
            return;
        }

        $oldNick = $user->getNick();
        $user->changeNick($newNick);

        // UnrealIRCd strips +r on every nick change but does NOT send UMODE2 -r to
        // services. We mirror the server behaviour here so the in-memory state stays
        // consistent.
        $user->applyModeChange('-r');

        $this->logger->info(sprintf('Nick change: %s → %s [%s]', $oldNick->value, $newNick->value, $user->uid->value));

        $this->eventDispatcher->dispatch(new UserNickChangedEvent($user->uid, $oldNick, $newNick));
    }

    // -------------------------------------------------------------------------
    // QUIT — user disconnected
    // Syntax: :uid QUIT :reason
    // -------------------------------------------------------------------------
    private function handleQuit(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $reason   = $message->trailing ?? '';

        if ('' === $sourceId) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            return;
        }

        $nick = $user->getNick();
        $uid  = $user->uid;

        // Remove user from all channels
        foreach ($this->channelRepository->all() as $channel) {
            $channel->removeMember($uid);
        }

        $this->userRepository->removeByUid($uid);

        $this->logger->info(sprintf('User quit: %s [%s] — %s', $nick->value, $uid->value, $reason));

        $this->eventDispatcher->dispatch(new UserQuitNetworkEvent($uid, $nick, $reason));
    }

    // -------------------------------------------------------------------------
    // SJOIN — channel state sync (burst) or user join (post-burst)
    // Syntax: :SID SJOIN timestamp #channel [+modes [modeParams...]] :buffer
    //
    // Buffer entries:
    //   - Users (UID with optional privilege prefixes): +@001AAAAAB
    //   - Bans:            &mask
    //   - Exemptions:      "mask
    //   - Invite excepts:  'mask
    // -------------------------------------------------------------------------
    private function handleSjoin(IRCMessage $message): void
    {
        if (count($message->params) < 2) {
            return;
        }

        $timestamp   = (int) $message->params[0];
        $channelStr  = $message->params[1];
        $buffer      = trim($message->trailing ?? '');

        try {
            $channelName = new ChannelName($channelStr);
        } catch (\InvalidArgumentException) {
            $this->logger->warning('SJOIN: invalid channel name: ' . $channelStr);
            return;
        }

        // Collect mode string (params[2] starts with +) and any mode parameters
        $modeStr = '';
        if (isset($message->params[2]) && str_starts_with($message->params[2], '+')) {
            $modeStr = implode(' ', array_slice($message->params, 2));
        }

        $channel  = $this->channelRepository->findByName($channelName);
        $isNewChannel = $channel === null;

        if ($isNewChannel) {
            $channel = new Channel(
                name:      $channelName,
                modes:     $modeStr,
                createdAt: new \DateTimeImmutable('@' . $timestamp),
            );
        } else {
            // Update modes if we're receiving an SJOIN for an existing channel
            // (e.g. during re-sync or mode correction)
            if ('' !== $modeStr) {
                $channel->setModes($modeStr);
            }
        }

        $joinedUids = [];

        foreach (explode(' ', $buffer) as $entry) {
            $entry = trim($entry);

            if ('' === $entry) {
                continue;
            }

            // Strip optional SJSBY extended info prefix: <setat,setby>
            if (str_starts_with($entry, '<')) {
                $closingPos = strpos($entry, '>');
                if ($closingPos !== false) {
                    $entry = substr($entry, $closingPos + 1);
                }
            }

            $firstChar = $entry[0] ?? '';

            // Mode list entries
            if ($firstChar === '&') {
                $channel->addBan(substr($entry, 1));
                continue;
            }

            if ($firstChar === '"') {
                $channel->addExempt(substr($entry, 1));
                continue;
            }

            if ($firstChar === "'") {
                $channel->addInviteException(substr($entry, 1));
                continue;
            }

            // Member entry — strip privilege prefixes
            $role = ChannelMemberRole::fromSjoinEntry($entry);

            try {
                $uid = new Uid($entry);
            } catch (\InvalidArgumentException) {
                $this->logger->debug('SJOIN: skipping non-UID member entry: ' . $entry);
                continue;
            }

            $channel->syncMember($uid, $role);
            $joinedUids[] = [$uid, $role];
        }

        $this->channelRepository->save($channel);

        if ($isNewChannel) {
            $this->logger->info(sprintf(
                'Channel synced: %s [%s, %d members]',
                $channelName->value,
                $modeStr ?: 'no modes',
                $channel->getMemberCount(),
            ));

            $this->eventDispatcher->dispatch(new ChannelSyncedEvent($channel));
        } else {
            // Post-burst join(s) — dispatch individual join events
            foreach ($joinedUids as [$uid, $role]) {
                $this->logger->info(sprintf(
                    'User %s joined %s [role: %s]',
                    $uid->value,
                    $channelName->value,
                    $role->label(),
                ));

                $this->eventDispatcher->dispatch(new UserJoinedChannelEvent($uid, $channelName, $role));
            }
        }
    }

    // -------------------------------------------------------------------------
    // PART — user leaves a channel voluntarily
    // Syntax: :uid PART #channel [:reason]
    // -------------------------------------------------------------------------
    private function handlePart(IRCMessage $message): void
    {
        $sourceId   = $message->prefix ?? '';
        $channelStr = $message->params[0] ?? '';
        $reason     = $message->trailing ?? '';

        if ('' === $sourceId || '' === $channelStr) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (\InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($channelName);
        $channel?->removeMember($user->uid);

        $this->logger->info(sprintf(
            'User %s parted %s [%s]',
            $user->getNick()->value,
            $channelName->value,
            $reason,
        ));

        $this->eventDispatcher->dispatch(
            new UserLeftChannelEvent($user->uid, $user->getNick(), $channelName, $reason, wasKicked: false)
        );
    }

    // -------------------------------------------------------------------------
    // KICK — a user is removed from a channel by another user or server
    // Syntax: :sourceUid KICK #channel targetUid [:reason]
    // -------------------------------------------------------------------------
    private function handleKick(IRCMessage $message): void
    {
        $channelStr = $message->params[0] ?? '';
        $targetId   = $message->params[1] ?? '';
        $reason     = $message->trailing ?? '';

        if ('' === $channelStr || '' === $targetId) {
            return;
        }

        $target = $this->resolveUser($targetId);

        if (null === $target) {
            return;
        }

        try {
            $channelName = new ChannelName($channelStr);
        } catch (\InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($channelName);
        $channel?->removeMember($target->uid);

        $this->logger->info(sprintf(
            'User %s was kicked from %s [%s]',
            $target->getNick()->value,
            $channelName->value,
            $reason,
        ));

        $this->eventDispatcher->dispatch(
            new UserLeftChannelEvent($target->uid, $target->getNick(), $channelName, $reason, wasKicked: true)
        );
    }

    // -------------------------------------------------------------------------
    // UMODE2 — user mode change propagation from S2S (e.g. SVSLOGIN sets +r)
    // Syntax: :uid UMODE2 <modestring>
    // -------------------------------------------------------------------------
    private function handleUmode2(IRCMessage $message): void
    {
        $sourceId = $message->prefix ?? '';
        $modeStr  = $message->params[0] ?? $message->trailing ?? '';

        if ('' === $sourceId || '' === $modeStr) {
            return;
        }

        $user = $this->resolveUser($sourceId);

        if (null === $user) {
            return;
        }

        $user->applyModeChange($modeStr);

        $this->logger->debug(sprintf(
            'UMODE2: %s [%s] modes → %s',
            $user->getNick()->value,
            $user->uid->value,
            $user->getModes(),
        ));
    }

    // -------------------------------------------------------------------------
    // MD — metadata update; we track "account" metadata to know SVSLOGIN worked
    // Syntax: :server MD client <uid> <key> [:<value>]
    // -------------------------------------------------------------------------
    private function handleMd(IRCMessage $message): void
    {
        if (($message->params[0] ?? '') !== 'client') {
            return;
        }

        $uidStr = $message->params[1] ?? '';
        $key    = $message->params[2] ?? '';
        $value  = $message->trailing ?? ($message->params[3] ?? '');

        if ('' === $uidStr || $key !== 'account') {
            return;
        }

        $this->logger->debug(sprintf('MD: client %s account = %s', $uidStr, $value));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolves a user by UID string (preferred) or by nick as fallback.
     * UnrealIRCd sends UIDs in server-sourced commands; nick-sourced is legacy.
     */
    private function resolveUser(string $sourceId): ?NetworkUser
    {
        // UIDs are 9 uppercase alphanumeric chars starting with a digit
        if (preg_match('/^[0-9][0-9A-Z]{8}$/', $sourceId)) {
            try {
                return $this->userRepository->findByUid(new Uid($sourceId));
            } catch (\InvalidArgumentException) {
            }
        }

        // Fallback: resolve by nick (old-style or pre-burst messages)
        try {
            return $this->userRepository->findByNick(new Nick($sourceId));
        } catch (\InvalidArgumentException) {
        }

        return null;
    }
}
