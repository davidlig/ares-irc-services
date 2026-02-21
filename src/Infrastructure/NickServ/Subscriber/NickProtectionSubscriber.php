<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\Bot\NickServBot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Protects registered nicknames from being used by unidentified users.
 *
 * On user join (UID received):
 *   1. If the nick is registered and the user already has +r mode → mark as
 *      auto-identified (the IRCd trusts the mode from the previous session).
 *   2. If the nick is registered and the user does NOT have +r → send a
 *      warning notice and immediately rename them to Guest-XXXXXXX.
 *
 * On user quit:
 *   - Updates last_seen_at and last_quit_message in the database.
 */
class NickProtectionSubscriber implements EventSubscriberInterface
{
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
            UserJoinedNetworkEvent::class => ['onUserJoined', 0],
            UserQuitNetworkEvent::class   => ['onUserQuit', 0],
        ];
    }

    public function onUserJoined(UserJoinedNetworkEvent $event): void
    {
        $user    = $event->user;
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
            ['nickname' => $nick],
            'nickserv',
            $language,
        );

        $this->nickServBot->sendNotice($user->uid->value, $warning);

        $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));

        $renameMsg = $this->translator->trans(
            'protection.renamed_to',
            ['guest_nick' => $guestNick],
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
