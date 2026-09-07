<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\CleanupChannel;

use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\UseCase\CleanupChannel\CleanupChannelMemoData;
use App\MemoServ\Application\UseCase\CleanupChannel\CleanupChannelMemoDataHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CleanupChannelMemoData::class)]
#[CoversClass(CleanupChannelMemoDataHandler::class)]
final class CleanupChannelMemoDataHandlerTest extends TestCase
{
    #[Test]
    public function deletesEveryMemoServReferenceToTheChannel(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('deleteAllForChannel')->with(84);
        $ignoreRepository = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepository->expects(self::once())->method('deleteAllForChannel')->with(84);
        $settingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepository->expects(self::once())->method('deleteAllForChannel')->with(84);

        $handler = new CleanupChannelMemoDataHandler($memoRepository, $ignoreRepository, $settingsRepository);

        $handler->handle(new CleanupChannelMemoData(84));
    }
}
