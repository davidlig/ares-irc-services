<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use Psr\Log\LoggerInterface;

final readonly class ForcedVhostApplier
{
    public function __construct(
        private OperIrcopRepositoryInterface $ircopRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private IdentifiedSessionRegistry $identifiedRegistry,
        private NickServNotifierInterface $notifier,
        private NetworkUserLookupPort $userLookup,
        private ActiveConnectionHolderInterface $connectionHolder,
        private LoggerInterface $logger,
    ) {
    }

    public function applyForcedVhostIfApplicable(int $nickId, string $nickname, string $uid): bool
    {
        $ircop = $this->ircopRepository->findByNickId($nickId);
        if (null === $ircop) {
            return false;
        }

        $role = $ircop->getRole();
        $pattern = $role->getForcedVhostPattern();

        if (null === $pattern || '' === $pattern) {
            return false;
        }

        if (!ForcedVhost::isValidPattern($pattern)) {
            $this->logger->warning('ForcedVhostApplier: invalid pattern stored for role', [
                'role' => $role->getName(),
                'pattern' => $pattern,
            ]);

            return false;
        }

        $forcedVhost = ForcedVhost::fromPattern($pattern);
        $vhost = $forcedVhost->generateVhost($nickname);

        $serverSid = $this->connectionHolder->getServerSid();
        $this->notifier->setUserVhost($uid, $vhost, $serverSid);

        $this->logger->info('ForcedVhostApplier: applied forced vhost', [
            'nickId' => $nickId,
            'nickname' => $nickname,
            'uid' => $uid,
            'vhost' => $vhost,
            'role' => $role->getName(),
        ]);

        return true;
    }

    public function updateVhostForRole(int $roleId, ?string $newPattern): void
    {
        $ircops = $this->ircopRepository->findByRoleId($roleId);

        if (empty($ircops)) {
            return;
        }

        foreach ($ircops as $ircop) {
            $nickId = $ircop->getNickId();
            $nick = $this->nickRepository->findById($nickId);

            if (null === $nick) {
                continue;
            }

            $uid = $this->identifiedRegistry->findUidByNick($nick->getNickname());

            if (null === $uid) {
                continue;
            }

            $user = $this->userLookup->findByUid($uid);
            if (null === $user) {
                continue;
            }

            if (null === $newPattern || '' === $newPattern) {
                $serverSid = $this->connectionHolder->getServerSid();
                $this->notifier->setUserVhost($uid, '', $serverSid);
                $this->logger->info('ForcedVhostApplier: cleared forced vhost (role pattern removed)', [
                    'nickId' => $nickId,
                    'uid' => $uid,
                    'roleId' => $roleId,
                ]);

                continue;
            }

            if (!ForcedVhost::isValidPattern($newPattern)) {
                $this->logger->warning('ForcedVhostApplier: invalid pattern for role update', [
                    'roleId' => $roleId,
                    'pattern' => $newPattern,
                ]);

                continue;
            }

            $forcedVhost = ForcedVhost::fromPattern($newPattern);
            $vhost = $forcedVhost->generateVhost($user->nick);

            $serverSid = $this->connectionHolder->getServerSid();
            $this->notifier->setUserVhost($uid, $vhost, $serverSid);

            $this->logger->info('ForcedVhostApplier: updated forced vhost for role change', [
                'nickId' => $nickId,
                'uid' => $uid,
                'vhost' => $vhost,
                'roleId' => $roleId,
            ]);
        }
    }
}
