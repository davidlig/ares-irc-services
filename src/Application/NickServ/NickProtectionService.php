<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\NickServ\Event\UserDeidentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
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
        private readonly NetworkUserLookupPort $userLookup,
        private readonly NickServNotifierInterface $notifier,
        private readonly BurstState $burstState,
        private readonly IdentifiedSessionRegistry $identifiedRegistry,
        private readonly PendingNickRestoreRegistryInterface $pendingRegistry,
        private readonly TranslatorInterface $translator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly string $guestPrefix = 'Guest-',
        private readonly string $defaultLanguage = 'en',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function onUserJoined(SenderView $user): void
    {
        if (!$this->burstState->isComplete()) {
            $this->burstState->addPending($user);

            return;
        }

        $this->enforceProtection($user);
    }

    /**
     * Called when a user changes nickname. Receives only primitives; no Core event types.
     */
    public function onNickChanged(string $uid, string $oldNick, string $newNick): void
    {
        $this->logger->debug(sprintf(
            'NickProtection onNickChanged: old=%s new=%s uid=%s burstComplete=%s',
            $oldNick,
            $newNick,
            $uid,
            $this->burstState->isComplete() ? 'yes' : 'no',
        ));

        if (!$this->burstState->isComplete()) {
            return;
        }

        if (str_starts_with($newNick, $this->guestPrefix) && $this->pendingRegistry->consume($uid)) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — SVSNICK to Guest echo, skipping protection',
                $oldNick,
                $uid,
                $newNick,
            ));

            return;
        }

        $registeredNick = $this->identifiedRegistry->findNick($uid);
        if (null !== $registeredNick && 0 === strcasecmp($registeredNick, $oldNick)) {
            // Get account info before removing from registry
            $account = $this->nickRepository->findByNick($registeredNick);
            if (null !== $account) {
                $this->eventDispatcher->dispatch(new UserDeidentifiedEvent(
                    $uid,
                    $account->getId(),
                    $registeredNick,
                ));
            }

            $this->identifiedRegistry->remove($uid);
            $sender = $this->userLookup->findByUid($uid);
            if (null !== $sender) {
                $this->notifier->setUserVhost($uid, '', $sender->serverSid);
            }
        }

        $account = $this->nickRepository->findByNick($newNick);

        if (null === $account || !$account->isRegistered()) {
            $this->logger->debug(sprintf(
                'NickProtection onNickChanged: skip (account null or not registered for %s)',
                $newNick,
            ));

            return;
        }

        $user = $this->userLookup->findByUid($uid);

        if (null === $user) {
            $this->logger->debug(sprintf(
                'NickProtection onNickChanged: skip (user null for uid %s)',
                $uid,
            ));

            return;
        }

        if (str_starts_with($oldNick, $this->guestPrefix) && $this->pendingRegistry->consume($uid)) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — SVSNICK restore echo, skipping protection',
                $oldNick,
                $uid,
                $newNick,
            ));

            return;
        }

        $registeredNick = $this->identifiedRegistry->findNick($uid);
        if (null !== $registeredNick && 0 === strcasecmp($registeredNick, $newNick)) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — identified in registry, skipping protection',
                $oldNick,
                $uid,
                $newNick,
            ));

            return;
        }

        if ($user->isIdentified) {
            $this->logger->info(sprintf(
                'Nick change: %s [%s] → %s — already identified, OK',
                $oldNick,
                $uid,
                $newNick,
            ));

            return;
        }

        $this->logger->info(sprintf(
            'Nick change: %s [%s] → %s — not identified, enforcing protection',
            $oldNick,
            $uid,
            $newNick,
        ));

        $this->enforceProtection($user);
    }

    /**
     * Called when a user quits the network. Receives only primitives; no Core event types.
     */
    public function onUserQuit(string $uid, string $nick, string $reason, string $ident, string $displayHost): void
    {
        $account = $this->nickRepository->findByNick($nick);

        if (null === $account) {
            $registeredNick = $this->identifiedRegistry->findNick($uid);
            if (null !== $registeredNick) {
                $account = $this->nickRepository->findByNick($registeredNick);
            }
        }

        $this->identifiedRegistry->remove($uid);

        if (null === $account) {
            return;
        }

        $account->markSeen();

        $origin = '' !== $ident ? $ident . '@' . $displayHost : $displayHost;
        $stored = '' !== $reason
            ? sprintf('%s (%s)', $reason, $origin)
            : ('' !== $origin ? $origin : null);

        $account->updateQuitMessage($stored);
        $this->nickRepository->save($account);
    }

    public function enforceProtection(SenderView $user): void
    {
        $nick = $user->nick;
        $account = $this->nickRepository->findByNick($nick);

        if (null === $account || !$account->isRegistered()) {
            return;
        }

        if ($user->isIdentified) {
            $account->markSeen();
            $this->nickRepository->save($account);

            // Register the session so IrcopModeApplier can find it
            $this->identifiedRegistry->register($user->uid, $account->getNickname());

            $this->logger->info(sprintf(
                'Nick protection: %s [%s] auto-identified (has +r)',
                $nick,
                $user->uid,
            ));

            // Dispatch event so subscribers (like OperRoleModesSubscriber) can react
            $this->eventDispatcher->dispatch(new NickIdentifiedEvent(
                $account->getId(),
                $account->getNickname(),
                $user->uid,
            ));

            return;
        }

        $language = $account->getLanguage() ?? $this->defaultLanguage;
        $botName = $this->notifier->getNick();

        $warning = $this->translator->trans(
            'protection.nick_in_use',
            ['%nickname%' => $nick, '%bot%' => $botName],
            'nickserv',
            $language,
        );

        $messageType = $account->getMessageType();
        $this->notifier->sendMessage($user->uid, $warning, $messageType);

        $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));

        $renameMsg = $this->translator->trans(
            'protection.renamed_to',
            ['%guest_nick%' => $guestNick, '%nickname%' => $nick, '%bot%' => $botName],
            'nickserv',
            $language,
        );

        $this->notifier->sendMessage($user->uid, $renameMsg, $messageType);
        $this->notifier->forceNick($user->uid, $guestNick);

        $this->logger->info(sprintf(
            'Nick protection: %s [%s] renamed to %s (nick in use, not identified)',
            $nick,
            $user->uid,
            $guestNick,
        ));
    }
}
