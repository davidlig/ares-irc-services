<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServChannelUnforbiddenSubscriber;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServChannelUnforbiddenSubscriber::class)]
final class ChanServChannelUnforbiddenSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEvent(): void
    {
        self::assertSame(
            [ChannelUnforbiddenEvent::class => ['onChannelUnforbidden', 0]],
            ChanServChannelUnforbiddenSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function delegatesChannelNameToApplication(): void
    {
        $enforcement = $this->createMock(ForbiddenChannelEnforcement::class);
        $enforcement->expects(self::once())->method('releaseUnforbiddenChannel')->with('#forbidden');

        new ChanServChannelUnforbiddenSubscriber($enforcement)->onChannelUnforbidden(new ChannelUnforbiddenEvent(
            channelName: '#forbidden',
            channelNameLower: '#forbidden',
            performedBy: 'Oper',
        ));
    }
}
