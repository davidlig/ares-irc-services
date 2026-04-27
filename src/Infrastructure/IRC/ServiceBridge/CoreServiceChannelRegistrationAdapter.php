<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ServiceChannelRegistrationPort;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Tracks channels that services join/part locally so the internal
 * ChannelRepository stays in sync with the IRCd state.
 *
 * When a service pseudo-client joins a channel via FJOIN/SJOIN, the IRCd
 * knows about it but our local repository does not — it only processes
 * *incoming* events. This adapter registers the channel locally so that
 * ChannelLookupPort can find it and provide the correct timestamp for
 * subsequent protocol commands (FMODE, FTOPIC, INVITE, etc.).
 */
final readonly class CoreServiceChannelRegistrationAdapter implements ServiceChannelRegistrationPort
{
    public function __construct(
        private ChannelRepositoryInterface $channelRepository,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function registerServiceChannelJoin(
        string $channelName,
        string $serviceUid,
        string $servicePrefixLetter,
        int $channelTimestamp,
        string $modes = '',
    ): void {
        try {
            $name = new ChannelName($channelName);
        } catch (InvalidArgumentException) {
            $this->logger->warning('Invalid channel name for service channel registration', [
                'channel' => $channelName,
            ]);

            return;
        }

        $uid = new Uid($serviceUid);
        $role = ChannelMemberRole::fromModeLetter($servicePrefixLetter) ?? ChannelMemberRole::Op;
        $prefixLetters = ChannelMemberRole::None !== $role ? [$role->toModeLetter()] : [];

        $channel = $this->channelRepository->findByName($name);

        if (null === $channel) {
            $channel = new Channel(
                name: $name,
                modes: $modes,
                createdAt: new DateTimeImmutable('@' . $channelTimestamp),
            );
            $channel->syncMember($uid, $role, $prefixLetters);
            $this->channelRepository->save($channel);

            $this->logger->debug('Registered new service-joined channel locally', [
                'channel' => $channelName,
                'uid' => $serviceUid,
                'timestamp' => $channelTimestamp,
            ]);

            return;
        }

        if ($channel->getCreatedAt()->getTimestamp() > $channelTimestamp) {
            $channel->updateCreatedAt(new DateTimeImmutable('@' . $channelTimestamp));
        }

        if (!$channel->isMember($uid)) {
            $channel->syncMember($uid, $role, $prefixLetters);
        }

        $this->channelRepository->save($channel);

        $this->logger->debug('Updated existing channel with service member locally', [
            'channel' => $channelName,
            'uid' => $serviceUid,
        ]);
    }

    public function unregisterServiceChannelPart(string $channelName, string $serviceUid): void
    {
        try {
            $name = new ChannelName($channelName);
        } catch (InvalidArgumentException) {
            return;
        }

        $channel = $this->channelRepository->findByName($name);
        if (null === $channel) {
            return;
        }

        $uid = new Uid($serviceUid);
        $channel->removeMember($uid);

        if ($channel->getMemberCount() < 1) {
            $this->channelRepository->remove($name);

            $this->logger->debug('Removed empty service channel from local repository', [
                'channel' => $channelName,
            ]);

            return;
        }

        $this->channelRepository->save($channel);
    }
}
