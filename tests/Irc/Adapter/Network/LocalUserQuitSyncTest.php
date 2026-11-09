<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Adapter\Network\LocalUserQuitSync;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(LocalUserQuitSync::class)]
final class LocalUserQuitSyncTest extends TestCase
{
    #[Test]
    public function dispatchesQuitForKilledUser(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $event): bool => $event instanceof UserQuitReceivedEvent
                && '001USER' === $event->sourceId && 'reason' === $event->reason,
        ));

        new LocalUserQuitSync($dispatcher)->apply('001USER', 'reason');
    }
}
