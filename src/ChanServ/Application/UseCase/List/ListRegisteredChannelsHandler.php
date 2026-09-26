<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\List;

use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

use function array_values;
use function ceil;
use function max;

final readonly class ListRegisteredChannelsHandler implements ListRegisteredChannelsHandlerInterface
{
    private int $pageSize;

    public function __construct(
        private RegisteredChannelRepositoryInterface $repository,
        private ChanUserAccountPort $accounts,
        int $pageSize,
    ) {
        $this->pageSize = max(1, $pageSize);
    }

    public function handle(ListRegisteredChannels $query): ListRegisteredChannelsResult
    {
        $page = max(1, $query->page);
        $total = $this->repository->countByPattern($query->pattern);
        $lastPage = (int) ceil($total / $this->pageSize);
        $channels = [];

        if ($page <= $lastPage) {
            $channels = $this->repository->searchByPattern(
                $query->pattern,
                ($page - 1) * $this->pageSize,
                $this->pageSize,
            );
        }

        $founderIds = [];
        foreach ($channels as $channel) {
            $founderId = $channel->getFounderNickId();
            if (0 < $founderId) {
                $founderIds[$founderId] = $founderId;
            }
        }
        $founderNames = [] === $founderIds ? [] : $this->accounts->findNicknamesByIds(array_values($founderIds));

        $entries = [];
        foreach ($channels as $channel) {
            $founderId = $channel->getFounderNickId();
            $entries[] = new ListedRegisteredChannel(
                $channel->getName(),
                $founderNames[$founderId] ?? null,
                $channel->getCreatedAt(),
                $channel->getLastUsedAt(),
                $channel->getStatus(),
            );
        }

        return new ListRegisteredChannelsResult($query->pattern, $page, $this->pageSize, $total, $entries);
    }
}
