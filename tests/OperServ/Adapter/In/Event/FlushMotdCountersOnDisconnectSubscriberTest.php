<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\OperServ\Adapter\In\Event\FlushMotdCountersOnDisconnectSubscriber;
use App\OperServ\Application\Port\Out\MotdCounterFlusher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(FlushMotdCountersOnDisconnectSubscriber::class)]
final class FlushMotdCountersOnDisconnectSubscriberTest extends TestCase
{
    #[Test]
    public function flushesWhenTheNetworkDisconnects(): void
    {
        $flusher = $this->createMock(MotdCounterFlusher::class);
        $flusher->expects(self::once())->method('flushShownCounts');
        self::assertSame('onDisconnect', FlushMotdCountersOnDisconnectSubscriber::getSubscribedEvents()[NetworkConnectionLostEvent::class]);
        new FlushMotdCountersOnDisconnectSubscriber($flusher)->onDisconnect();
    }

    #[Test]
    public function failedFlushDoesNotInterruptDisconnect(): void
    {
        $flusher = $this->createMock(MotdCounterFlusher::class);
        $flusher->expects(self::once())->method('flushShownCounts')->willThrowException(new RuntimeException('database unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        new FlushMotdCountersOnDisconnectSubscriber($flusher, $logger)->onDisconnect();
    }
}
