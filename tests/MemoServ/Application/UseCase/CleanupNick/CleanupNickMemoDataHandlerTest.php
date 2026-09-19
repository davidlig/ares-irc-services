<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\CleanupNick;

use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\UseCase\CleanupNick\CleanupNickMemoData;
use App\MemoServ\Application\UseCase\CleanupNick\CleanupNickMemoDataHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanupNickMemoData::class)]
#[CoversClass(CleanupNickMemoDataHandler::class)]
final class CleanupNickMemoDataHandlerTest extends TestCase
{
    #[Test]
    public function deletesEveryMemoServReferenceToTheNick(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('deleteAllForNick')->with(42);
        $ignoreRepository = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepository->expects(self::once())->method('deleteAllForNick')->with(42);
        $settingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepository->expects(self::once())->method('deleteAllForNick')->with(42);

        $handler = new CleanupNickMemoDataHandler($memoRepository, $ignoreRepository, $settingsRepository);

        $handler->handle(new CleanupNickMemoData(42));
    }
}
