<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\NicknameReservation;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;

use function sprintf;

readonly class ForbiddenNickService
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickForceService $forceService,
        private NickNetworkUserLookup $userLookup,
        private NickProtectionNotifier $notifier,
        private NicknameReservation $nicknameReservation,
        private NickServActivitySink $logger,
        private string $defaultLanguage = 'en',
    ) {}

    public function forbid(string $nickname, string $reason, ?string $operatorNick = null): RegisteredNick
    {
        $account = $this->nickRepository->findByNick($nickname);

        if (null !== $account && !$account->isForbidden()) {
            $this->logger->info(sprintf(
                'ForbiddenNick: Dropping existing account %s (status: %s) before creating forbidden',
                $nickname,
                $account->getStatus()->value,
            ));
        }

        $forbidden = RegisteredNick::createForbidden($nickname, $reason, $this->defaultLanguage);

        $this->nickRepository->save($forbidden);

        $this->logger->info(sprintf(
            'ForbiddenNick: Nickname %s has been forbidden. Reason: %s. Operator: %s',
            $nickname,
            $reason,
            $operatorNick ?? 'unknown',
        ));

        $this->applyNickReservation($nickname, $reason);

        $onlineUser = $this->userLookup->findByNick($nickname);

        if (null !== $onlineUser) {
            $this->notifyAndForceGuest($onlineUser->uid, $reason, $nickname);
        }

        return $forbidden;
    }

    public function updateReason(RegisteredNick $forbidden, string $newReason): void
    {
        $nickname = $forbidden->getNickname();
        $forbidden->updateForbiddenReason($newReason);
        $this->nickRepository->save($forbidden);

        $this->applyNickReservation($nickname, $newReason);

        $onlineUser = $this->userLookup->findByNick($nickname);

        if (null !== $onlineUser) {
            $this->notifyAndForceGuest($onlineUser->uid, $newReason, $nickname);
        }
    }

    public function unforbid(string $nickname): bool
    {
        $account = $this->nickRepository->findByNick($nickname);

        if (null === $account || !$account->isForbidden()) {
            return false;
        }

        $this->nickRepository->delete($account);

        $this->removeNickReservation($nickname);

        $this->logger->info(sprintf(
            'ForbiddenNick: Nickname %s has been unforbidden',
            $nickname,
        ));

        return true;
    }

    public function notifyAndForceGuest(string $uid, string $reason, ?string $nickname = null): void
    {
        if (null === $nickname) {
            $user = $this->userLookup->findByUid($uid);
            $nickname = $user->nick ?? 'Unknown';
        }

        $this->notifier->notifyForbidden($uid, $nickname, $reason, $this->defaultLanguage);
        $this->forceService->forceGuestNick($uid, null, 'forbidden-nick');

        $this->applyNickReservation($nickname, $reason);
    }

    private function applyNickReservation(string $nickname, string $reason): void
    {
        $this->nicknameReservation->reserve($nickname, $reason);
    }

    private function removeNickReservation(string $nickname): void
    {
        $this->nicknameReservation->release($nickname);
    }
}
