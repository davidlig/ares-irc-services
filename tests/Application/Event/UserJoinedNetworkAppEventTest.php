<?php

declare(strict_types=1);

namespace App\Tests\Application\Event;

use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\Port\UserJoinedNetworkDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserJoinedNetworkAppEvent::class)]
final class UserJoinedNetworkAppEventTest extends TestCase
{
    #[Test]
    public function storesUserDto(): void
    {
        $user = new UserJoinedNetworkDTO('UID1', 'User', 'ident', 'host', 'cloak', 'ip', 'display');
        $event = new UserJoinedNetworkAppEvent($user);

        self::assertSame($user, $event->user);
    }
}
