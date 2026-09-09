<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveConnectionHolderInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\UserVhostSetter;
use App\NickServ\Application\Port\In\IdentifiedSessionQuery;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\NickServ\Application\Port\In\NickVhostDisplayResolver;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Domain\ValueObject\ForcedVhost;
use Psr\Log\LoggerInterface;

final readonly class ForcedVhostApplier
{
    public function __construct(
        private OperIrcopRepositoryInterface $ircopRepository,
        private NickProjectionQuery $nicks,
        private IdentifiedSessionQuery $identifiedSessions,
        private UserVhostSetter $vhostSetter,
        private NetworkUserLookupPort $userLookup,
        private ActiveConnectionHolderInterface $connectionHolder,
        private NickVhostDisplayResolver $vhostDisplayResolver,
        private LoggerInterface $logger,
    ) {}

    public function applyForcedVhostIfApplicable(int $nickId, string $nickname, string $uid): bool
    {
        $ircop = $this->ircopRepository->findByNickId($nickId);
        $result = false;

        if (null !== $ircop) {
            $role = $ircop->getRole();
            $pattern = $role->getForcedVhostPattern();

            if (null !== $pattern && '' !== $pattern) {
                if (ForcedVhost::isValidPattern($pattern)) {
                    $forcedVhost = ForcedVhost::fromPattern($pattern);
                    $vhost = $forcedVhost->generateVhost($nickname);

                    $serverSid = $this->connectionHolder->getServerSid();
                    if (null === $serverSid) {
                        return false;
                    }
                    $this->vhostSetter->setUserVhost($uid, $vhost, $serverSid);

                    $this->logger->info('ForcedVhostApplier: applied forced vhost', [
                        'nickId' => $nickId,
                        'nickname' => $nickname,
                        'uid' => $uid,
                        'vhost' => $vhost,
                        'role' => $role->getName(),
                    ]);

                    $result = true;
                } else {
                    $this->logger->warning('ForcedVhostApplier: invalid pattern stored for role', [
                        'role' => $role->getName(),
                        'pattern' => $pattern,
                    ]);
                }
            }
        }

        return $result;
    }

    public function updateVhostForRole(int $roleId, ?string $newPattern): void
    {
        $ircops = $this->ircopRepository->findByRoleId($roleId);

        if (empty($ircops)) {
            return;
        }

        foreach ($ircops as $ircop) {
            $nickId = $ircop->getNickId();
            $nick = $this->nicks->findById($nickId);

            if (null === $nick) {
                continue;
            }

            $uid = $this->identifiedSessions->findUidByNick($nick->nickname);

            if (null === $uid) {
                continue;
            }

            $user = $this->userLookup->findByUid($uid);
            if (null === $user) {
                continue;
            }

            $serverSid = $this->connectionHolder->getServerSid();

            if (null === $newPattern || '' === $newPattern) {
                $personalVhost = $this->vhostDisplayResolver->getDisplayVhost($nick->vhost);
                if (null !== $serverSid) {
                    $this->vhostSetter->setUserVhost($uid, $personalVhost, $serverSid);
                }
                $this->logger->info('ForcedVhostApplier: restored personal vhost (role pattern removed)', [
                    'nickId' => $nickId,
                    'uid' => $uid,
                    'roleId' => $roleId,
                    'personalVhost' => $personalVhost,
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

            if (null !== $serverSid) {
                $this->vhostSetter->setUserVhost($uid, $vhost, $serverSid);
            }

            $this->logger->info('ForcedVhostApplier: updated forced vhost for role change', [
                'nickId' => $nickId,
                'uid' => $uid,
                'vhost' => $vhost,
                'roleId' => $roleId,
            ]);
        }
    }
}
