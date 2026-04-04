<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
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

        $onlineUser = $this->userLookup->findByNick($nickname);

        if (null !== $onlineUser) {
            $this->notifyAndForceGuest($onlineUser->uid, $reason);
        }

        return $forbidden;
    }

    public function updateReason(RegisteredNick $forbidden, string $newReason): void
    {
        $forbidden->updateForbiddenReason($newReason);
        $this->nickRepository->save($forbidden);

        $onlineUser = $this->userLookup->findByNick($forbidden->getNickname());

        if (null !== $onlineUser) {
            $this->notifyAndForceGuest($onlineUser->uid, $newReason);
        }
    }

    public function unforbid(string $nickname): bool
    {
        $account = $this->nickRepository->findByNick($nickname);

        if (null === $account || !$account->isForbidden()) {
            return false;
        }

        $this->nickRepository->delete($account);

        $this->logger->info(sprintf(
            'ForbiddenNick: Nickname %s has been unforbidden',
            $nickname,
        ));

        return true;
    }

    public function notifyAndForceGuest(string $uid, string $reason): void
    {
        $message = $this->translator->trans(
            'protection.nick_forbidden',
            ['%reason%' => $reason],
            'nickserv',
            $this->defaultLanguage,
        );

        $this->notifier->sendMessage($uid, $message, 'NOTICE');
        $this->forceService->forceGuestNick($uid, null, 'forbidden-nick');
    }
}
