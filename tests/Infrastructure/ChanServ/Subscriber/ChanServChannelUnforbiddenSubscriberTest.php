<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
use App\Infrastructure\ChanServ\Subscriber\ChanServChannelUnforbiddenSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServChannelUnforbiddenSubscriber::class)]
final class ChanServChannelUnforbiddenSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        self::assertSame(
            [ChannelUnforbiddenEvent::class => ['onChannelUnforbidden', 0]],
            ChanServChannelUnforbiddenSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onChannelUnforbiddenPartsChannelWhenChannelExistsOnNetwork(): void
    {
        $channelView = new \App\Application\Port\ChannelView(
            name: '#forbidden',
            modes: '+ntims',
            topic: null,
            memberCount: 0,
        );

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($channelView);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('partChannelAsService')->with('#forbidden');

        $subscriber = new ChanServChannelUnforbiddenSubscriber($channelServiceActions, $channelLookup);
        $subscriber->onChannelUnforbidden(new ChannelUnforbiddenEvent(
            channelName: '#forbidden',
            channelNameLower: '#forbidden',
            performedBy: 'Oper',
        ));
    }

    #[Test]
    public function onChannelUnforbiddenDoesNothingWhenChannelNotOnNetwork(): void
    {
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('partChannelAsService');

        $subscriber = new ChanServChannelUnforbiddenSubscriber($channelServiceActions, $channelLookup);
        $subscriber->onChannelUnforbidden(new ChannelUnforbiddenEvent(
            channelName: '#forbidden',
            channelNameLower: '#forbidden',
            performedBy: 'Oper',
        ));
    }
}
