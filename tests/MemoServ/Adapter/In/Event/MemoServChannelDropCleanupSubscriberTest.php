<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\Application\ChanServ\PublishedEvent\ChannelDropCleanupEvent;
use App\MemoServ\Adapter\In\Event\MemoServChannelDropCleanupSubscriber;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\UseCase\CleanupChannel\CleanupChannelMemoDataHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoServChannelDropCleanupSubscriber::class)]
final class MemoServChannelDropCleanupSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToThePublishedChannelCleanupEvent(): void
    {
        self::assertSame(
            [ChannelDropCleanupEvent::class => ['onChannelDrop', 0]],
            MemoServChannelDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function mapsThePublishedEventToTheCleanupUseCase(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('deleteAllForChannel')->with(456);
        $ignoreRepository = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepository->expects(self::once())->method('deleteAllForChannel')->with(456);
        $settingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepository->expects(self::once())->method('deleteAllForChannel')->with(456);

        $subscriber = new MemoServChannelDropCleanupSubscriber(
            new CleanupChannelMemoDataHandler($memoRepository, $ignoreRepository, $settingsRepository),
        );

        $subscriber->onChannelDrop(new ChannelDropCleanupEvent(456));
    }
}
