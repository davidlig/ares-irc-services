<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Entity\RegisteredNick;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

readonly class NickSuspensionService
{
    public function __construct(
        private NetworkUserLookupPort $userLookup,
        private NickForceService $forceService,
        private string $guestPrefix = 'Guest-',
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * If the suspended user is connected, force rename to guest nick
     * and de-identify them (remove +r mode and clear session registry).
     */
    public function enforceSuspension(RegisteredNick $account): void
    {
        $nickname = $account->getNickname();

        $onlineUser = $this->userLookup->findByNick($nickname);

        if (null === $onlineUser) {
            $this->logger->debug(sprintf(
                'NickSuspension: %s is not connected, no action needed',
                $nickname,
            ));

            return;
        }

        $this->logger->info(sprintf(
            'NickSuspension: %s [%s] is connected, forcing rename',
            $nickname,
            $onlineUser->uid,
        ));

        $this->forceService->forceGuestNick($onlineUser->uid, null, 'suspension');
    }
}
