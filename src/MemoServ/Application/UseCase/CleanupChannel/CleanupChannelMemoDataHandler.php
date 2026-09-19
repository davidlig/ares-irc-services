<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\CleanupChannel;

use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;

final readonly class CleanupChannelMemoDataHandler
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {}

    public function handle(CleanupChannelMemoData $command): void
    {
        $this->memoRepository->deleteAllForChannel($command->channelId);
        $this->memoIgnoreRepository->deleteAllForChannel($command->channelId);
        $this->memoSettingsRepository->deleteAllForChannel($command->channelId);
    }
}
