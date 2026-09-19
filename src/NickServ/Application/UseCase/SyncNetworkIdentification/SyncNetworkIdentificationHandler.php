<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\SyncNetworkIdentification;

use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\SessionLanguageTracker;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\NickServ\Application\Service\NickProtectionService;

use function strcasecmp;

final readonly class SyncNetworkIdentificationHandler implements SyncNetworkIdentificationHandlerInterface
{
    public function __construct(
        private NickProtectionService $nickProtectionService,
        private IdentifiedSessionTracker $identifiedSessions,
        private SessionLanguageTracker $sessionLanguages,
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickServEventPublisher $eventPublisher,
    ) {}

    public function handle(SyncNetworkIdentification $command): void
    {
        $user = $command->user;
        $identifiedNick = $this->identifiedSessions->findNick($user->uid);

        if ($user->isIdentified) {
            if (null !== $identifiedNick && 0 === strcasecmp($identifiedNick, $user->nick)) {
                return;
            }

            if (null !== $identifiedNick) {
                $this->removeIdentification($user->uid, $identifiedNick);
            }

            $this->nickProtectionService->enforceProtection($user, $command->occurredAt);

            return;
        }

        $this->removeIdentification($user->uid, $identifiedNick);
    }

    private function removeIdentification(string $uid, ?string $identifiedNick): void
    {
        $this->identifiedSessions->remove($uid);
        $this->sessionLanguages->remove($uid);

        if (null === $identifiedNick) {
            return;
        }

        $account = $this->nickRepository->findByNick($identifiedNick);
        if (null === $account) {
            return;
        }

        $this->eventPublisher->publish(new UserDeidentifiedEvent(
            $uid,
            $account->getId(),
            $identifiedNick,
        ));
    }
}
