<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupChannelAkick;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;

final readonly class CleanupChannelAkickHandler
{
    public function __construct(private ChannelAkickRepositoryInterface $akickRepository) {}

    public function handle(CleanupChannelAkick $command): void
    {
        $this->akickRepository->deleteByChannelId($command->channelId);
    }
}
