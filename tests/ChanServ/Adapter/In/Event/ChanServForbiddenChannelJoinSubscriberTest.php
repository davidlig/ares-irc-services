<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServForbiddenChannelJoinSubscriber;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServForbiddenChannelJoinSubscriber::class)]
final class ChanServForbiddenChannelJoinSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToJoinAndSynchronizationAtPriorityTen(): void
    {
        self::assertSame(
            [
                UserJoinedChannelEvent::class => ['onUserJoinedChannel', 10],
                ChannelSynchronizedEvent::class => ['onChannelSynced', 10],
            ],
            ChanServForbiddenChannelJoinSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function delegatesJoinedUserToApplication(): void
    {
        $enforcement = $this->createMock(ForbiddenChannelEnforcement::class);
        $enforcement->expects(self::once())
            ->method('enforceForbiddenUserJoin')
            ->with('#forbidden', 'AAA123');

        new ChanServForbiddenChannelJoinSubscriber($enforcement)
            ->onUserJoinedChannel(new UserJoinedChannelEvent('AAA123', '#forbidden'));
    }

    #[Test]
    public function delegatesChannelSynchronizationToApplication(): void
    {
        $enforcement = $this->createMock(ForbiddenChannelEnforcement::class);
        $enforcement->expects(self::once())
            ->method('enforceConfiguredForbiddenChannel')
            ->with('#forbidden');

        new ChanServForbiddenChannelJoinSubscriber($enforcement)
            ->onChannelSynced(new ChannelSynchronizedEvent('#forbidden'));
    }
}
