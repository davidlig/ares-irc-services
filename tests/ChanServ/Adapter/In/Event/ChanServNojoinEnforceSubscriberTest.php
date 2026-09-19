<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServNojoinEnforceSubscriber;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoin;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceNojoin\SynchronizeAllChannelNojoinHandlerInterface;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServNojoinEnforceSubscriber::class)]
final class ChanServNojoinEnforceSubscriberTest extends TestCase
{
    #[Test]
    public function exposesJoinBeforeRankPriorityAndTranslatesJoinAliases(): void
    {
        self::assertSame([
            UserJoinedChannelEvent::class => ['onUserJoined', 10],
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 10],
        ], ChanServNojoinEnforceSubscriber::getSubscribedEvents());

        $handler = $this->createMock(EnforceChannelNojoinHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->with(self::callback(
            static fn (EnforceChannelNojoin $command): bool => '#MixedCase' === $command->channelName
                && '001A' === $command->memberUid,
        ));
        $subscriber = new ChanServNojoinEnforceSubscriber(
            $handler,
            $this->createStub(SynchronizeAllChannelNojoinHandlerInterface::class),
        );
        $event = new UserJoinedChannelEvent('001A', '#MixedCase');

        $subscriber->onUserJoined($event);
        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function translatesNetworkSynchronizationCompletion(): void
    {
        $synchronize = $this->createMock(SynchronizeAllChannelNojoinHandlerInterface::class);
        $synchronize->expects(self::once())->method('handle');
        $subscriber = new ChanServNojoinEnforceSubscriber(
            $this->createStub(EnforceChannelNojoinHandlerInterface::class),
            $synchronize,
        );

        $subscriber->onSyncComplete(new NetworkSynchronizationCompletedEvent('001'));
    }
}
