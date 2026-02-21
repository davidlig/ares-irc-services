<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\Bot\NickServBot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly NickServBot $nickServBot,
        private readonly TranslatorInterface $translator,
        private readonly string $guestPrefix = 'Guest-',
        private readonly string $defaultLanguage = 'en',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedNetworkEvent::class    => ['onUserJoined', 0],
            UserQuitNetworkEvent::class      => ['onUserQuit', 0],
            // Priority -1000: fires after ActiveConnectionHolder (priority -999) has stored the connection.
            NetworkBurstCompleteEvent::class => ['onBurstComplete', -1000],
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

        $pending            = $this->pendingUsers;
        $this->pendingUsers = [];

        foreach ($pending as $user) {
            $this->enforceProtection($user);
        }
    }

    private function enforceProtection(NetworkUser $user): void
    {
        $nick    = $user->getNick()->value;
        $account = $this->nickRepository->findByNick($nick);

        if ($account === null) {
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

    public function onUserQuit(UserQuitNetworkEvent $event): void
    {
        $account = $this->nickRepository->findByNick($event->nick->value);

        if ($account === null) {
            return;
        }

        $account->markSeen();
        $account->updateQuitMessage($event->reason ?: null);
        $this->nickRepository->save($account);
    }
}
