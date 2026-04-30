<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Application\ApplicationPort\ServiceUidRegistry;
use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\OperServ\Service\PseudoClientUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\Motd;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use App\Domain\OperServ\ValueObject\GlobalMessageMask;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use ValueError;

use function in_array;
use function sprintf;
use function strtolower;

/**
 * Sends configured MOTD messages to users when they connect to the network.
 *
 * Pseudo-clients (nick!ident@vhost) stay connected as long as at least one
 * active MOTD references them. Multiple MOTDs sharing the same mask reuse
 * the same pseudo-client, which quits only when the last MOTD is removed.
 *
 * Late MOTD additions (after sync) are reconciled on every user join.
 * On services restart, active pseudo-clients are re-introduced.
 */
final class MotdOnConnectSubscriber implements EventSubscriberInterface
{
    private const int PERMANENT_RESERVE_SECONDS = 365 * 86400;

    private bool $isSynced = false;

    /**
     * Map of nickname_lower => pseudo-client aggregated info.
     *
     * @var array<string, array{uid: string, mask: GlobalMessageMask, motdIds: int[]}>
     */
    private array $pseudoClients = [];

    public function __construct(
        private readonly MotdRepositoryInterface $motdRepository,
        private readonly ServiceUidRegistry $uidRegistry,
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly PseudoClientUidGenerator $pseudoUidGenerator,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly SendNoticePort $sendNoticePort,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSyncCompleteEvent::class => ['onSyncComplete', -50],
            UserJoinedNetworkAppEvent::class => ['onUserJoined', -60],
        ];
    }

    public function onSyncComplete(): void
    {
        $this->isSynced = true;
        $this->ensurePseudoClients();
    }

    public function onUserJoined(UserJoinedNetworkAppEvent $event): void
    {
        if (!$this->isSynced) {
            return;
        }

        $this->ensurePseudoClients();
        $this->cleanupStale();
        $this->sendMotds($event);
    }

    /**
     * Ensures every active MOTD (mask format) has a pseudo-client connected.
     * Idempotent — safe to call multiple times (sync, user join, late add).
     */
    private function ensurePseudoClients(): void
    {
        $activeMotds = $this->motdRepository->findActive();

        foreach ($activeMotds as $motd) {
            $botSpec = $motd->getBotNickname();

            if (null !== $this->uidRegistry->getUidByNickname($botSpec)) {
                continue;
            }

            try {
                $mask = GlobalMessageMask::fromString($botSpec);
            } catch (ValueError) {
                continue;
            }

            $nickLower = strtolower($mask->nickname);

            if (isset($this->pseudoClients[$nickLower])) {
                $pc = &$this->pseudoClients[$nickLower];
                if (!in_array($motd->getId(), $pc['motdIds'], true)) {
                    $pc['motdIds'][] = $motd->getId();
                }
                continue;
            }

            if (null !== $this->userLookup->findByNick($mask->nickname)) {
                continue;
            }

            if (null !== $this->nickRepository->findByNick($nickLower)) {
                continue;
            }

            $module = $this->connectionHolder->getProtocolModule();
            $serverSid = $this->connectionHolder->getServerSid();
            $nickReservation = null !== $module ? $module->getNickReservation() : null;

            if (null === $module || null === $serverSid || null === $nickReservation) {
                return;
            }

            $uid = $this->pseudoUidGenerator->generate();
            if (null === $uid) {
                return;
            }

            $reserveSeconds = null !== $motd->getExpiresAt()
                ? max(1, $motd->getExpiresAt()->getTimestamp() - time())
                : self::PERMANENT_RESERVE_SECONDS;

            $nickReservation->reserveNickWithDuration(
                $mask->nickname,
                $reserveSeconds,
                sprintf('MOTD #%d pseudo-client', $motd->getId()),
            );

            $module->getServiceActions()->introducePseudoClient(
                $serverSid,
                $mask->nickname,
                $mask->ident,
                $mask->vhost,
                $uid,
                $mask->nickname,
            );

            $this->pseudoClients[$nickLower] = [
                'uid' => $uid,
                'mask' => $mask,
                'motdIds' => [$motd->getId()],
            ];

            $this->logger->info('MotdOnConnect: introduced pseudo-client.', [
                'motd_id' => $motd->getId(),
                'nickname' => $mask->nickname,
                'uid' => $uid,
            ]);
        }
    }

    /**
     * Removes stale MOTD IDs from pseudo-clients. Quits the pseudo-client
     * only when no active MOTDs reference it anymore.
     */
    private function cleanupStale(): void
    {
        $all = $this->motdRepository->findAll();
        $activeMap = [];
        foreach ($all as $m) {
            if ($m->isEnabled() && !$m->isExpired()) {
                $activeMap[$m->getId()] = true;
            }
        }

        $toQuit = [];
        foreach ($this->pseudoClients as $nickLower => $pc) {
            $pc['motdIds'] = array_values(array_filter(
                $pc['motdIds'],
                static fn (int $motdId): bool => isset($activeMap[$motdId]),
            ));

            if ([] === $pc['motdIds']) {
                $toQuit[] = $nickLower;
            } else {
                $this->pseudoClients[$nickLower] = $pc;
            }
        }

        foreach ($toQuit as $nickLower) {
            $this->quitPseudoClient($this->pseudoClients[$nickLower]['uid']);
            unset($this->pseudoClients[$nickLower]);
        }
    }

    private function sendMotds(UserJoinedNetworkAppEvent $event): void
    {
        $activeMotds = $this->motdRepository->findActive();

        foreach ($activeMotds as $motd) {
            $botSpec = $motd->getBotNickname();

            $serviceUid = $this->uidRegistry->getUidByNickname($botSpec);
            if (null !== $serviceUid) {
                $this->sendNoticePort->sendMessage(
                    $serviceUid,
                    $event->user->uid,
                    $motd->getText(),
                    $motd->getMessageType(),
                );

                continue;
            }

            try {
                $mask = GlobalMessageMask::fromString($botSpec);
            } catch (ValueError) {
                continue;
            }

            $nickLower = strtolower($mask->nickname);
            $pc = $this->pseudoClients[$nickLower] ?? null;

            if (null !== $pc) {
                $this->sendNoticePort->sendMessage(
                    $pc['uid'],
                    $event->user->uid,
                    $motd->getText(),
                    $motd->getMessageType(),
                );
            }
        }
    }

    private function quitPseudoClient(string $uid): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $serverSid = $this->connectionHolder->getServerSid();

        if (null !== $module && null !== $serverSid) {
            $module->getServiceActions()->quitPseudoClient($serverSid, $uid, 'MOTD expired');
        }

        $this->logger->info('MotdOnConnect: quit pseudo-client.', [
            'uid' => $uid,
        ]);
    }
}
