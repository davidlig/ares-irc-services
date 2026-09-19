<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServAkickEnforceSubscriber;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkick;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicks;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicksHandlerInterface;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServAkickEnforceSubscriber::class)]
final class ChanServAkickEnforceSubscriberTest extends TestCase
{
    #[Test]
    public function exposesLegacyPrioritiesAndTranslatesJoin(): void
    {
        self::assertSame([
            UserJoinedChannelEvent::class => ['onUserJoined', 0],
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 0],
        ], ChanServAkickEnforceSubscriber::getSubscribedEvents());

        $handler = $this->createMock(EnforceChannelAkickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (EnforceChannelAkick $command): bool => '#MixedCase' === $command->channelName
                && '001A' === $command->memberUid,
        ));
        $subscriber = new ChanServAkickEnforceSubscriber(
            $handler,
            $this->createStub(SynchronizeAllChannelAkicksHandlerInterface::class),
        );

        $subscriber->onUserJoined(new UserJoinedChannelEvent('001A', '#MixedCase'));
    }

    #[Test]
    public function translatesNetworkSynchronizationCompletion(): void
    {
        $synchronize = $this->createMock(SynchronizeAllChannelAkicksHandlerInterface::class);
        $synchronize->expects(self::once())->method('handle')->with(self::isInstanceOf(SynchronizeAllChannelAkicks::class));
        $subscriber = new ChanServAkickEnforceSubscriber(
            $this->createStub(EnforceChannelAkickHandlerInterface::class),
            $synchronize,
        );

        $subscriber->onSyncComplete(new NetworkSynchronizationCompletedEvent('001'));
    }
}
