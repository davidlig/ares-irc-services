<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Infrastructure\ChanServ\Subscriber\ChanServAccessChannelDropSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServAccessChannelDropSubscriber::class)]
final class ChanServAccessChannelDropSubscriberTest extends TestCase
{
    private ChannelAccessRepositoryInterface&MockObject $accessRepository;

    private ChanServAccessChannelDropSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);

        $this->subscriber = new ChanServAccessChannelDropSubscriber(
            $this->accessRepository,
        );
    }

    #[Test]
    public function subscribesToChannelDropEvent(): void
    {
        $this->accessRepository->expects(self::never())->method('deleteByChannelId');

        self::assertSame(
            [ChannelDropEvent::class => ['onChannelDrop', 0]],
            ChanServAccessChannelDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesAccessForDroppedChannel(): void
    {
        $event = new ChannelDropEvent(
            channelId: 12345,
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'manual',
        );

        $this->accessRepository
            ->expects(self::once())
            ->method('deleteByChannelId')
            ->with(12345);

        $this->subscriber->onChannelDrop($event);
    }

    #[Test]
    public function deletesAccessForDifferentChannelIds(): void
    {
        $event = new ChannelDropEvent(
            channelId: 999,
            channelName: '#other',
            channelNameLower: '#other',
            reason: 'inactivity',
        );

        $this->accessRepository
            ->expects(self::once())
            ->method('deleteByChannelId')
            ->with(999);

        $this->subscriber->onChannelDrop($event);
    }
}
