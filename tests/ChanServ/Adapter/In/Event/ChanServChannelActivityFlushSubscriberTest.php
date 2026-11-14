<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServChannelActivityFlushSubscriber;
use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ChanServChannelActivityFlushSubscriber::class)]
final class ChanServChannelActivityFlushSubscriberTest extends TestCase
{
    #[Test]
    public function itSubscribesToNetworkDisconnection(): void
    {
        self::assertSame(
            [NetworkConnectionLostEvent::class => 'onConnectionLost'],
            ChanServChannelActivityFlushSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function itFlushesPendingActivityWhenTheConnectionIsLost(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willReturn(1);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $buffer = new ChannelActivityBuffer($connection, $logger);
        $buffer->record(7, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));

        $subscriber = new ChanServChannelActivityFlushSubscriber($buffer);
        $subscriber->onConnectionLost(new NetworkConnectionLostEvent());
    }

    #[Test]
    public function itLogsButDoesNotBreakShutdownWhenTheFlushFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willThrowException(new RuntimeException('db unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Could not flush a chunk of deferred ChanServ channel activity; it will be retried.',
            self::callback(static fn (array $context): bool => $context['exception'] instanceof RuntimeException),
        );
        $buffer = new ChannelActivityBuffer($connection, $logger);
        $buffer->record(7, new DateTimeImmutable('2026-01-01 00:00:00 UTC'));

        $subscriber = new ChanServChannelActivityFlushSubscriber($buffer);
        $subscriber->onConnectionLost(new NetworkConnectionLostEvent());
    }
}
