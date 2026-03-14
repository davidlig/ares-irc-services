<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemoServ\Subscriber;

use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Infrastructure\MemoServ\Subscriber\MemoServChannelDropCleanupSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoServChannelDropCleanupSubscriber::class)]
final class MemoServChannelDropCleanupSubscriberTest extends TestCase
{
    private MemoRepositoryInterface&MockObject $memoRepository;

    private MemoIgnoreRepositoryInterface&MockObject $memoIgnoreRepository;

    private MemoSettingsRepositoryInterface&MockObject $memoSettingsRepository;

    private MemoServChannelDropCleanupSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $this->memoIgnoreRepository = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $this->memoSettingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);

        $this->subscriber = new MemoServChannelDropCleanupSubscriber(
            $this->memoRepository,
            $this->memoIgnoreRepository,
            $this->memoSettingsRepository,
        );
    }

    #[Test]
    public function subscribesToChannelDropEvent(): void
    {
        $this->memoRepository->expects(self::never())->method('deleteAllForChannel');
        $this->memoIgnoreRepository->expects(self::never())->method('deleteAllForChannel');
        $this->memoSettingsRepository->expects(self::never())->method('deleteAllForChannel');
        self::assertSame(
            [ChannelDropEvent::class => ['onChannelDrop', 0]],
            MemoServChannelDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesAllMemoDataForDroppedChannel(): void
    {
        $event = new ChannelDropEvent(
            channelId: 12345,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'manual',
        );

        $this->memoRepository
            ->expects(self::once())
            ->method('deleteAllForChannel')
            ->with(12345);

        $this->memoIgnoreRepository
            ->expects(self::once())
            ->method('deleteAllForChannel')
            ->with(12345);

        $this->memoSettingsRepository
            ->expects(self::once())
            ->method('deleteAllForChannel')
            ->with(12345);

        $this->subscriber->onChannelDrop($event);
    }
}
