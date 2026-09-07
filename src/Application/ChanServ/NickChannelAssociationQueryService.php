<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use App\Application\ChanServ\Port\In\NickChannelAssociation;
use App\Application\ChanServ\Port\In\NickChannelAssociationQuery;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

final readonly class NickChannelAssociationQueryService implements NickChannelAssociationQuery
{
    public function __construct(
        private ChannelAccessRepositoryInterface $accessRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
    ) {}

    public function findForNick(int $nickId): array
    {
        $accessEntries = $this->accessRepository->findByNick($nickId);
        $founderChannels = $this->channelRepository->findByFounderNickId($nickId);
        $successorChannels = $this->channelRepository->findBySuccessorNickId($nickId);

        /** @var array<int, array{name: string, type: 'founder'|'successor'|'access', level: ?int}> $channels */
        $channels = [];
        foreach ($founderChannels as $channel) {
            $channels[$channel->getId()] = ['name' => $channel->getName(), 'type' => 'founder', 'level' => null];
        }
        foreach ($successorChannels as $channel) {
            $channels[$channel->getId()] ??= ['name' => $channel->getName(), 'type' => 'successor', 'level' => null];
        }

        $missingChannelIds = [];
        foreach ($accessEntries as $access) {
            if (!isset($channels[$access->getChannelId()])) {
                $missingChannelIds[] = $access->getChannelId();
                $channels[$access->getChannelId()] = ['name' => '', 'type' => 'access', 'level' => $access->getLevel()];
            }
        }
        if ([] !== $missingChannelIds) {
            foreach ($this->channelRepository->findByIds($missingChannelIds) as $channel) {
                if (isset($channels[$channel->getId()])) {
                    $channels[$channel->getId()]['name'] = $channel->getName();
                }
            }
        }

        return array_map(
            static fn (array $channel): NickChannelAssociation => new NickChannelAssociation($channel['name'], $channel['type'], $channel['level']),
            array_values($channels),
        );
    }
}
