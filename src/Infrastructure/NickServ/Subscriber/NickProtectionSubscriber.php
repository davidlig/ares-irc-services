<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\Bot\NickServBot;
use App\Infrastructure\NickServ\PendingNickRestoreRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

/**
 * Protects registered nicknames from being used by unidentified users.
 *
 * During the initial server burst, UIDs arrive before the connection is ready
 * for writing. Those users are queued and processed when the burst ends
 * (NetworkBurstCompleteEvent, after ActiveConnectionHolder stores the connection).
 *
 * On user join (post-burst):
 *   1. Registered nick + +r mode → mark seen (auto-identified from previous session).
 *   2. Registered nick + no +r  → warning notice + SVSNICK to Guest-XXXXXXX.
 *
 * On user quit:
 *   - Updates last_seen_at and last_quit_message in the database.
 */
class NickProtectionSubscriber implements EventSubscriberInterface
{
    private bool $burstComplete = false;

    /** @var NetworkUser[] Users received during the burst, processed after EOS. */
    private array $pendingUsers = [];

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly NickServBot $nickServBot,
        private readonly PendingNickRestoreRegistry $pendingRegistry,
        private readonly IdentifiedSessionRegistry $identifiedRegistry,
        private readonly TranslatorInterface $translator,
        private readonly string $guestPrefix = 'Guest-',
        private readonly string $defaultLanguage = 'en',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Priorities per Symfony 7.4 event_dispatcher: higher = runs earlier; range -256..256.
     *
     * @see https://symfony.com/doc/7.4/event_dispatcher.html
     */
    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedNetworkEvent::class => ['onUserJoined', 0],
            UserQuitNetworkEvent::class => ['onUserQuit', 0],
            UserNickChangedEvent::class => ['onNickChanged', 0],
            NetworkBurstCompleteEvent::class => ['onBurstComplete', -256],
        ];
    }

    public function onUserJoined(UserJoinedNetworkEvent $event): void
    {
        if (!$this->burstComplete) {
            // Connection not yet available — queue for post-burst processing.
            $this->pendingUsers[] = $event->user;

            return;
        }

        $this->enforceProtection($event->user);
    }

    /**
     * Called after the network burst ends and the connection is ready.
     * Processes all users that joined during the burst.
     */
    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->burstComplete = true;

        $pending = $this->pendingUsers;
        $this->pendingUsers = [];

        foreach ($pending as $user) {
            $this->enforceProtection($user);
        }
    }

    private function enforceProtection(NetworkUser $user): void
    {
        $nick = $user->getNick()->value;
        $account = $this->nickRepository->findByNick($nick);

        if (null === $account || !$account->isRegistered()) {
            return;
        }

        // User already has +r mode set (e.g. services restart scenario)
        if ($user->isIdentified()) {
            $account->markSeen();
            $this->nickRepository->save($account);

            $this->logger->info(sprintf(
                'Nick protection: %s [%s] auto-identified (has +r)',
                $nick,
                $user->uid->value,
            ));

            return;
        }

        // Nick is registered but user is not identified → warn + rename
        $language = $account->getLanguage() ?? $this->defaultLanguage;

        $warning = $this->translator->trans(
            'protection.nick_in_use',
            ['%nickname%' => $nick],
            'nickserv',
            $language,
        );

        $this->nickServBot->sendNotice($user->uid->value, $warning);

        $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));

        $renameMsg = $this->translator->trans(
            'protection.renamed_to',
            ['%guest_nick%' => $guestNick, '%nickname%' => $nick],
            'nickserv',
            $language,
        );

        $this->nickServBot->sendNotice($user->uid->value, $renameMsg);
        $this->nickServBot->forceNick($user->uid->value, $guestNick);

        $this->logger->info(sprintf(
            'Nick protection: %s [%s] renamed to %s (nick in use, not identified)',
            $nick,
            $user->uid->value,
            $guestNick,
        ));
    }

    /**
     * Fires whenever a user changes their nickname.
     *
     * Two cases require action:
     *   1. New nick is registered and user is not identified → warn + rename.
     *   2. Nick change was triggered by our own SVSNICK (IDENTIFY flow) → skip.
     *
     * NOTE: NetworkStateSubscriber strips +r from memory on every nick change
     * (mirroring the IRCd behaviour). So isIdentified() is reliable here and
     * we do NOT need the pre-emptive applyModeChange('+r') in command handlers.
     *
     * Nick changes during the burst are intentionally skipped — they are already
     * covered by the post-burst pending queue populated by onUserJoined.
     */
    public function onNickChanged(UserNickChangedEvent $event): void
    {
        $this->logger->debug(sprintf(
            'NickProtection onNickChanged: old=%s new=%s uid=%s burstComplete=%s',
            $event->oldNick->value,
            $event->newNick->value,
            $event->uid->value,
            $this->burstComplete ? 'yes' : 'no',
        ));

        if (!$this->burstComplete) {
            return;
        }

        $newNick = $event->newNick->value;

        // Consume mark when we see our "rename to Guest" echo (new nick = Guest-*).
        // Must run before account check so we consume here; otherwise we return early
        // (Guest is not registered) and the mark would still be set when user does /nick <registered>.
        if (str_starts_with($newNick, $this->guestPrefix) && $this->pendingRegistry->consume($event->uid->value)) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — SVSNICK to Guest echo, skipping protection',
                $event->oldNick->value,
                $event->uid->value,
                $newNick,
            ));

            return;
        }

        $account = $this->nickRepository->findByNick($newNick);

        if (null === $account || !$account->isRegistered()) {
            $this->logger->debug(sprintf(
                'NickProtection onNickChanged: skip (account null or not registered for %s)',
                $newNick,
            ));

            return;
        }

        // Resolve user by UID. NetworkStateSubscriber (10) has already run and
        // updated the in-memory user's nick; we run at 10 so the user object is up to date.
        // Using findByUid avoids depending on byNick index update order.
        $user = $this->userRepository->findByUid($event->uid);

        if (null === $user) {
            $this->logger->debug(sprintf(
                'NickProtection onNickChanged: skip (user null for uid %s)',
                $event->uid->value,
            ));

            return;
        }

        // Skip protection when this NICK is the echo of our "restore after IDENTIFY" SVSNICK:
        // old nick is Guest-*, new nick is registered. UMODE2 +r may arrive after the NICK.
        if (str_starts_with($event->oldNick->value, $this->guestPrefix) && $this->pendingRegistry->consume($event->uid->value)) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — SVSNICK restore echo, skipping protection',
                $event->oldNick->value,
                $event->uid->value,
                $newNick,
            ));

            return;
        }

        $identified = $user->isIdentified();
        $this->logger->debug(sprintf(
            'NickProtection onNickChanged: user modes=%s isIdentified=%s',
            $user->getModes(),
            $identified ? 'yes' : 'no',
        ));

        if ($identified) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — already identified, OK',
                $event->oldNick->value,
                $event->uid->value,
                $newNick,
            ));

            return;
        }

        $this->logger->info(sprintf(
            'Nick change: %s [%s] → %s — not identified, enforcing protection',
            $event->oldNick->value,
            $event->uid->value,
            $newNick,
        ));

        $this->enforceProtection($user);
    }

    public function onUserQuit(UserQuitNetworkEvent $event): void
    {
        // Primary lookup: current nick at quit time.
        $account = $this->nickRepository->findByNick($event->nick->value);

        // Fallback: the user may have changed nick (e.g. 'david') after identifying
        // as a registered nick ('davidlig'). Look up via the session registry.
        if (null === $account) {
            $registeredNick = $this->identifiedRegistry->findNick($event->uid->value);
            if (null !== $registeredNick) {
                $account = $this->nickRepository->findByNick($registeredNick);
            }
        }

        // Always clean up the session registry entry for this UID.
        $this->identifiedRegistry->remove($event->uid->value);

        if (null === $account) {
            return;
        }

        $account->markSeen();

        // Build quit message including the user's IRC origin (ident@host).
        $origin = '' !== $event->ident ? $event->ident . '@' . $event->displayHost : $event->displayHost;
        $stored = '' !== $event->reason
            ? sprintf('%s (%s)', $event->reason, $origin)
            : ('' !== $origin ? $origin : null);

        $account->updateQuitMessage($stored);
        $this->nickRepository->save($account);
    }
}
