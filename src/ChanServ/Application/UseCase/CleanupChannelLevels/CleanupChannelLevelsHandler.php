<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelLevels;

use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;

final readonly class CleanupChannelLevelsHandler
{
    public function __construct(private ChannelLevelRepositoryInterface $levelRepository) {}

    public function handle(CleanupChannelLevels $command): void
    {
        $this->levelRepository->removeAllForChannel($command->channelId);
    }
}
