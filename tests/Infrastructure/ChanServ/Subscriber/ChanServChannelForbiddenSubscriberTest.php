<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
use App\Infrastructure\ChanServ\Subscriber\ChanServChannelForbiddenSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServChannelForbiddenSubscriber::class)]
final class ChanServChannelForbiddenSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        self::assertSame(
            [ChannelForbiddenEvent::class => ['onChannelForbidden', 0]],
            ChanServChannelForbiddenSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onChannelForbiddenEnforcesWhenChannelExistsOnNetwork(): void
    {
        $channelView = new ChannelView(
            name: '#forbidden',
            modes: '+nt',
            topic: null,
            memberCount: 3,
            members: [
                ['uid' => 'AAA123', 'roleLetter' => 'o'],
                ['uid' => 'BBB456', 'roleLetter' => 'v'],
                ['uid' => 'CCC789', 'roleLetter' => ''],
            ],
            timestamp: 1000000,
        );

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn($channelView);

        $kickedUsers = [];
        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::once())->method('joinChannelAsService')->with('#forbidden', 1000000);
        $channelServiceActions->expects(self::exactly(3))->method('kickFromChannel')
            ->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kickedUsers): void {
                $kickedUsers[] = $uid;
            });
        $channelServiceActions->expects(self::once())->method('setChannelModes')->with('#forbidden', '+ntims', []);

        $subscriber = new ChanServChannelForbiddenSubscriber($channelServiceActions, $channelLookup);
        $subscriber->onChannelForbidden(new ChannelForbiddenEvent(
            channelId: 1,
            channelName: '#forbidden',
            channelNameLower: '#forbidden',
            reason: 'spam',
            performedBy: 'Oper',
        ));

        self::assertSame(['AAA123', 'BBB456', 'CCC789'], $kickedUsers);
    }

    #[Test]
    public function onChannelForbiddenDoesNothingWhenChannelNotOnNetwork(): void
    {
        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#forbidden')->willReturn(null);

        $channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelServiceActions->expects(self::never())->method('joinChannelAsService');
        $channelServiceActions->expects(self::never())->method('kickFromChannel');
        $channelServiceActions->expects(self::never())->method('setChannelModes');

        $subscriber = new ChanServChannelForbiddenSubscriber($channelServiceActions, $channelLookup);
        $subscriber->onChannelForbidden(new ChannelForbiddenEvent(
            channelId: 1,
            channelName: '#forbidden',
            channelNameLower: '#forbidden',
            reason: 'spam',
            performedBy: 'Oper',
        ));
    }
}
