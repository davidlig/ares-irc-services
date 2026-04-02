<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Entity\RegisteredNick;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;
use function strtoupper;
use function substr;
use function uniqid;

readonly class NickSuspensionService
{
    public function __construct(
        private NetworkUserLookupPort $userLookup,
        private NickServNotifierInterface $notifier,
        private IdentifiedSessionRegistry $identifiedRegistry,
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

        $uid = $onlineUser->uid;

        $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));

        $this->logger->info(sprintf(
            'NickSuspension: %s [%s] is connected, renaming to %s and de-identifying',
            $nickname,
            $uid,
            $guestNick,
        ));

        $this->identifiedRegistry->remove($uid);

        $this->notifier->setUserAccount($uid, '0');

        $this->notifier->forceNick($uid, $guestNick);
    }
}
