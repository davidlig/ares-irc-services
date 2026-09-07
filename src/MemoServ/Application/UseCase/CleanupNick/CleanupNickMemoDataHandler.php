<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\CleanupNick;

use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;

final readonly class CleanupNickMemoDataHandler
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {}

    public function handle(CleanupNickMemoData $command): void
    {
        $this->memoRepository->deleteAllForNick($command->nickId);
        $this->memoIgnoreRepository->deleteAllForNick($command->nickId);
        $this->memoSettingsRepository->deleteAllForNick($command->nickId);
    }
}
