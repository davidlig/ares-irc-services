<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServForbiddenChannelBurstSubscriber;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServForbiddenChannelBurstSubscriber::class)]
final class ChanServForbiddenChannelBurstSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEventAtPriorityTen(): void
    {
        self::assertSame(
            [NetworkSynchronizationCompletedEvent::class => ['onNetworkSyncComplete', 10]],
            ChanServForbiddenChannelBurstSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function delegatesNetworkSynchronizationToApplication(): void
    {
        $enforcement = $this->createMock(ForbiddenChannelEnforcement::class);
        $enforcement->expects(self::once())->method('enforceAllForbiddenChannels');

        new ChanServForbiddenChannelBurstSubscriber($enforcement)
            ->onNetworkSyncComplete(new NetworkSynchronizationCompletedEvent('001'));
    }
}
