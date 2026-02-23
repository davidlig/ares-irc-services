<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;
use function str_starts_with;
use function strcasecmp;

/**
 * Application service: enforces nick protection rules.
 * Decides when to mark seen, warn + rename, or update quit message.
 * Used by NickProtectionSubscriber (Infrastructure) which only forwards events.
 */
final readonly class NickProtectionService
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly NickServNotifierInterface $notifier,
        private readonly BurstState $burstState,
        private readonly IdentifiedSessionRegistry $identifiedRegistry,
        private readonly PendingNickRestoreRegistryInterface $pendingRegistry,
        private readonly TranslatorInterface $translator,
        private readonly string $guestPrefix = 'Guest-',
        private readonly string $defaultLanguage = 'en',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onUserJoined(NetworkUser $user): void
    {
        if (!$this->burstState->isComplete()) {
            $this->burstState->addPending($user);

            return;
        }

        $this->enforceProtection($user);
    }

    /**
     * Called after the network burst ends and the connection is ready.
     * Processes all users that joined during the burst.
     */
    public function onBurstComplete(): void
    {
        $this->burstState->markComplete();
        $pending = $this->burstState->takePending();

        foreach ($pending as $user) {
            $this->enforceProtection($user);
        }
    }

    public function onNickChanged(UserNickChangedEvent $event): void
    {
        $this->logger->debug(sprintf(
            'NickProtection onNickChanged: old=%s new=%s uid=%s burstComplete=%s',
            $event->oldNick->value,
            $event->newNick->value,
            $event->uid->value,
            $this->burstState->isComplete() ? 'yes' : 'no',
        ));

        if (!$this->burstState->isComplete()) {
            return;
        }

        $newNick = $event->newNick->value;

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

        $user = $this->userRepository->findByUid($event->uid);

        if (null === $user) {
            $this->logger->debug(sprintf(
                'NickProtection onNickChanged: skip (user null for uid %s)',
                $event->uid->value,
            ));

            return;
        }

        if (str_starts_with($event->oldNick->value, $this->guestPrefix) && $this->pendingRegistry->consume($event->uid->value)) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — SVSNICK restore echo, skipping protection',
                $event->oldNick->value,
                $event->uid->value,
                $newNick,
            ));

            return;
        }

        $registeredNick = $this->identifiedRegistry->findNick($event->uid->value);
        if (null !== $registeredNick && 0 === strcasecmp($registeredNick, $newNick)) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — identified in registry, skipping protection',
                $event->oldNick->value,
                $event->uid->value,
                $newNick,
            ));

            return;
        }

        if ($user->isIdentified()) {
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
        $account = $this->nickRepository->findByNick($event->nick->value);

        if (null === $account) {
            $registeredNick = $this->identifiedRegistry->findNick($event->uid->value);
            if (null !== $registeredNick) {
                $account = $this->nickRepository->findByNick($registeredNick);
            }
        }

        $this->identifiedRegistry->remove($event->uid->value);

        if (null === $account) {
            return;
        }

        $account->markSeen();

        $origin = '' !== $event->ident ? $event->ident . '@' . $event->displayHost : $event->displayHost;
        $stored = '' !== $event->reason
            ? sprintf('%s (%s)', $event->reason, $origin)
            : ('' !== $origin ? $origin : null);

        $account->updateQuitMessage($stored);
        $this->nickRepository->save($account);
    }

    private function enforceProtection(NetworkUser $user): void
    {
        $nick = $user->getNick()->value;
        $account = $this->nickRepository->findByNick($nick);

        if (null === $account || !$account->isRegistered()) {
            return;
        }

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

        $language = $account->getLanguage() ?? $this->defaultLanguage;

        $warning = $this->translator->trans(
            'protection.nick_in_use',
            ['%nickname%' => $nick],
            'nickserv',
            $language,
        );

        $this->notifier->sendNotice($user->uid->value, $warning);

        $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));

        $renameMsg = $this->translator->trans(
            'protection.renamed_to',
            ['%guest_nick%' => $guestNick, '%nickname%' => $nick],
            'nickserv',
            $language,
        );

        $this->notifier->sendNotice($user->uid->value, $renameMsg);
        $this->notifier->forceNick($user->uid->value, $guestNick);

        $this->logger->info(sprintf(
            'Nick protection: %s [%s] renamed to %s (nick in use, not identified)',
            $nick,
            $user->uid->value,
            $guestNick,
        ));
    }
}
