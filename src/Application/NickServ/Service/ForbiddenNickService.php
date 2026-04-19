<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function sprintf;

readonly class ForbiddenNickService
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickForceService $forceService,
        private NetworkUserLookupPort $userLookup,
        private NickServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private ActiveConnectionHolderInterface $connectionHolder,
        private LoggerInterface $logger,
        private string $defaultLanguage = 'en',
    ) {
    }

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

        $this->removeNickReservation($nickname);

        $this->nickRepository->delete($account);

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
            $nickname = $user?->nick ?? 'Unknown';
        }

        $message = $this->translator->trans(
            'protection.nick_forbidden',
            ['%nickname%' => $nickname, '%reason%' => $reason],
            'nickserv',
            $this->defaultLanguage,
        );

        $this->notifier->sendMessage($uid, $message, 'NOTICE');
        $this->forceService->forceGuestNick($uid, null, 'forbidden-nick');

        $this->applyNickReservation($nickname, $reason);
    }

    private function applyNickReservation(string $nickname, string $reason): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->debug('ForbiddenNick: no protocol module, skip nick reservation');

            return;
        }

        $reservation = $module->getNickReservation();
        if (null === $reservation) {
            $this->logger->debug('ForbiddenNick: protocol does not support nick reservation');

            return;
        }

        $reservation->reserveNick($nickname, $reason);
    }

    private function removeNickReservation(string $nickname): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->debug('ForbiddenNick: no protocol module, skip nick release');

            return;
        }

        $reservation = $module->getNickReservation();
        if (null === $reservation) {
            $this->logger->debug('ForbiddenNick: protocol does not support nick reservation');

            return;
        }

        $reservation->releaseNick($nickname);
    }
}
