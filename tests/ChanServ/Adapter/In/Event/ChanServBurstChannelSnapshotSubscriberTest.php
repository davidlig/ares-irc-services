<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServBurstChannelSnapshotSubscriber;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Port\Out\RegisteredChannelBurstSnapshot;
use App\Irc\Application\PublishedEvent\NetworkConnectionEstablishedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServBurstChannelSnapshotSubscriber::class)]
final class ChanServBurstChannelSnapshotSubscriberTest extends TestCase
{
    #[Test]
    public function snapshotsAreBoundToConnectionAndSynchronizationLifecycle(): void
    {
        self::assertSame([
            NetworkConnectionEstablishedEvent::class => 'onConnect',
            NetworkConnectionLostEvent::class => 'onEnd',
            NetworkSynchronizationCompletedEvent::class => ['onEnd', -512],
        ], ChanServBurstChannelSnapshotSubscriber::getSubscribedEvents());
        $snapshot = $this->createMock(RegisteredChannelBurstSnapshot::class);
        $snapshot->expects(self::once())->method('beginBurst');
        $snapshot->expects(self::exactly(2))->method('endBurst');
        $subscriber = new ChanServBurstChannelSnapshotSubscriber($snapshot, new BurstRankEventScope());
        $subscriber->onConnect();
        $subscriber->onEnd();
        $subscriber->onEnd();
    }
}
