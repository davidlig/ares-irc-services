<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\NickServ\Application\Port\Out\AssociatedChannel;
use App\NickServ\Application\Port\Out\NickAssociatedChannelsPort;

use function count;

final readonly class DoctrineNickAssociatedChannelsAdapter implements NickAssociatedChannelsPort
{
    public function __construct(
        private ChannelAccessRepositoryInterface $accessRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {}

    /**
     * @return list<AssociatedChannel>
     */
    public function findChannelsForNick(int $nickId): array
    {
        $accessEntries = $this->accessRepository->findByNick($nickId);
        $founderChannels = $this->channelRepository->findByFounderNickId($nickId);
        $successorChannels = $this->channelRepository->findBySuccessorNickId($nickId);

        if ([] === $accessEntries && [] === $founderChannels && [] === $successorChannels) {
            return [];
        }

        /** @var array<int, array{name: string, type: 'founder'|'successor'|'access', level: ?int}> $channels */
        $channels = [];

        foreach ($founderChannels as $channel) {
            $channels[$channel->getId()] = [
                'name' => $channel->getName(),
                'type' => 'founder',
                'level' => null,
            ];
        }

        foreach ($successorChannels as $channel) {
            if (!isset($channels[$channel->getId()])) {
                $channels[$channel->getId()] = [
                    'name' => $channel->getName(),
                    'type' => 'successor',
                    'level' => null,
                ];
            }
        }

        $accessChannelIds = [];
        foreach ($accessEntries as $access) {
            if (!isset($channels[$access->getChannelId()])) {
                $accessChannelIds[] = $access->getChannelId();
                $channels[$access->getChannelId()] = [
                    'name' => '',
                    'type' => 'access',
                    'level' => $access->getLevel(),
                ];
            }
        }

        if (0 < count($accessChannelIds)) {
            $channelEntities = $this->channelRepository->findByIds($accessChannelIds);
            foreach ($channelEntities as $channel) {
                if (isset($channels[$channel->getId()])) {
                    $channels[$channel->getId()]['name'] = $channel->getName();
                }
            }
        }

        $result = [];
        foreach ($channels as $channelData) {
            $result[] = new AssociatedChannel(
                name: $channelData['name'],
                type: $channelData['type'],
                level: $channelData['level'],
            );
        }

        return $result;
    }
}
