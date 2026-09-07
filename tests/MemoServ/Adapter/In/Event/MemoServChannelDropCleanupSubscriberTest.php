<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\Domain\ChanServ\Event\ChannelDropCleanupEvent;
use App\MemoServ\Adapter\In\Event\MemoServChannelDropCleanupSubscriber;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use DateTimeImmutable;
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
            [ChannelDropCleanupEvent::class => ['onChannelDrop', 0]],
            MemoServChannelDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesAllMemoDataForDroppedChannel(): void
    {
        $event = new ChannelDropCleanupEvent(
            channelId: 456,
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
        );

        $this->memoRepository
            ->expects(self::once())
            ->method('deleteAllForChannel')
            ->with(456);

        $this->memoIgnoreRepository
            ->expects(self::once())
            ->method('deleteAllForChannel')
            ->with(456);

        $this->memoSettingsRepository
            ->expects(self::once())
            ->method('deleteAllForChannel')
            ->with(456);

        $this->subscriber->onChannelDrop($event);
    }
}
