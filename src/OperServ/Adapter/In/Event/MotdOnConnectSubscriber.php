<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceChannelRegistrationPort;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Application\Port\In\NickCollisionResolver;
use App\OperServ\Adapter\Out\Irc\PseudoClientUidGenerator;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\MotdRepository;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use DateTimeImmutable;
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
        private readonly MotdRepository $motdRepository,
        private readonly ServiceUidRegistry $uidRegistry,
        private readonly ActiveProtocolModuleHolderInterface $connectionHolder,
        private readonly ChannelLookupPort $channelLookup,
        private readonly ServiceChannelRegistrationPort $channelRegistration,
        private readonly PseudoClientUidGenerator $pseudoUidGenerator,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly NickAccountQuery $nickAccounts,
        private readonly SendNoticePort $sendNoticePort,
        private readonly NickCollisionResolver $nickCollisionResolver,
        private readonly ?string $debugChannel,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', -50],
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
        $now = new DateTimeImmutable();
        $activeMotds = $this->motdRepository->findActiveAt($now);

        foreach ($activeMotds as $motd) {
            $botSpec = $motd->botNickname;

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
                if (!in_array($motd->id, $pc['motdIds'], true)) {
                    $pc['motdIds'][] = $motd->id;
                }
                continue;
            }

            $existingUser = $this->userLookup->findByNick($mask->nickname);
            if (null !== $existingUser) {
                $this->nickCollisionResolver->forceGuestNick($existingUser->uid, null, 'motd-collision');

                $this->logger->info('MotdOnConnect: renamed colliding user for MOTD pseudo-client.', [
                    'motd_id' => $motd->id,
                    'nickname' => $mask->nickname,
                    'uid' => $existingUser->uid,
                ]);
            }

            if (null !== $this->nickAccounts->findIdByNick($nickLower)) {
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

            $reserveSeconds = null !== $motd->expiresAt
                ? max(1, $motd->expiresAt->getTimestamp() - $now->getTimestamp())
                : self::PERMANENT_RESERVE_SECONDS;

            $nickReservation->reserveNickWithDuration(
                $mask->nickname,
                $reserveSeconds,
                sprintf('MOTD #%d pseudo-client', $motd->id),
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
                'motdIds' => [$motd->id],
            ];

            $this->joinPseudoClientToDebugChannel($uid);

            $this->logger->info('MotdOnConnect: introduced pseudo-client.', [
                'motd_id' => $motd->id,
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
        $now = new DateTimeImmutable();
        foreach ($all as $motd) {
            if ($motd->enabled && !$motd->isExpiredAt($now)) {
                $activeMap[$motd->id] = true;
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
        $activeMotds = $this->motdRepository->findActiveAt(new DateTimeImmutable());

        foreach ($activeMotds as $motd) {
            $botSpec = $motd->botNickname;

            $serviceUid = $this->uidRegistry->getUidByNickname($botSpec);
            if (null !== $serviceUid) {
                $this->sendNoticePort->sendMessage(
                    $serviceUid,
                    $event->user->uid,
                    $motd->text,
                    $this->messageType($motd->delivery),
                );
                $this->motdRepository->recordShown($motd->id);

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
                    $motd->text,
                    $this->messageType($motd->delivery),
                );
                $this->motdRepository->recordShown($motd->id);
            }
        }
    }

    /** @return 'NOTICE'|'PRIVMSG' */
    private function messageType(MessageDelivery $delivery): string
    {
        return match ($delivery) {
            MessageDelivery::NonInteractive => 'NOTICE',
            MessageDelivery::Interactive => 'PRIVMSG',
        };
    }

    private function joinPseudoClientToDebugChannel(string $uid): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        $serverSid = $this->connectionHolder->getServerSid();

        if (null === $module || null === $serverSid) {
            return;
        }

        $channel = $this->channelLookup->findByChannelName($this->debugChannel);
        $channelTimestamp = null !== $channel ? $channel->timestamp : time();
        $module->getServiceActions()->joinChannelAsService($serverSid, $this->debugChannel, $uid, '', $channelTimestamp);
        $this->channelRegistration->registerServiceChannelJoin($this->debugChannel, $uid, '', $channelTimestamp);
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
