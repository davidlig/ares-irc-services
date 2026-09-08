<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelHistory;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;

final readonly class CleanupChannelHistoryHandler
{
    public function __construct(private ChannelHistoryRepositoryInterface $historyRepository) {}

    public function handle(CleanupChannelHistory $command): void
    {
        $this->historyRepository->deleteByChannelId($command->channelId);
    }
}
