<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Domain\Entity\RegisteredNick;

use function sprintf;

readonly class NickSuspensionService
{
    public function __construct(
        private NickNetworkUserLookup $userLookup,
        private NickForceService $forceService,
        private string $guestPrefix = 'Guest-',
        private ?NickServActivitySink $logger = null,
    ) {}

    /**
     * If the suspended user is connected, force rename to guest nick
     * and de-identify them (remove +r mode and clear session registry).
     */
    public function enforceSuspension(RegisteredNick $account): void
    {
        $nickname = $account->getNickname();

        $onlineUser = $this->userLookup->findByNick($nickname);

        if (null === $onlineUser) {
            $this->logger?->debug(sprintf(
                'NickSuspension: %s is not connected, no action needed',
                $nickname,
            ));

            return;
        }

        $this->logger?->info(sprintf(
            'NickSuspension: %s [%s] is connected, forcing rename',
            $nickname,
            $onlineUser->uid,
        ));

        $this->forceService->forceGuestNick($onlineUser->uid, null, 'suspension');
    }

    public function getGuestPrefix(): string
    {
        return $this->guestPrefix;
    }
}
