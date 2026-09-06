<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\LocalUserModeSync;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(LocalUserModeSync::class)]
final class LocalUserModeSyncTest extends TestCase
{
    #[Test]
    public function applyDispatchesUserModeChangedEvent(): void
    {
        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event) use (&$dispatched): bool {
                if ($event instanceof UserModeChangedEvent) {
                    $dispatched[] = $event;

                    return true;
                }

                return false;
            }))
            ->willReturnArgument(0);

        $sync = new LocalUserModeSync($eventDispatcher);
        $uid = new Uid('001ABC123');

        $sync->apply($uid, '+i');

        self::assertCount(1, $dispatched);
        self::assertSame($uid, $dispatched[0]->uid);
        self::assertSame('+i', $dispatched[0]->modeDelta);
    }
}
