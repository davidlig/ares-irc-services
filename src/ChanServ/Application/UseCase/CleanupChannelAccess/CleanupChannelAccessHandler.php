<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelAccess;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;

final readonly class CleanupChannelAccessHandler
{
    public function __construct(private ChannelAccessRepositoryInterface $accessRepository) {}

    public function handle(CleanupChannelAccess $command): void
    {
        $this->accessRepository->deleteByChannelId($command->channelId);
    }
}
