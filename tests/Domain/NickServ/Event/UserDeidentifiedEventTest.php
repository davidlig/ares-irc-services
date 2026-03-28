<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Event\UserDeidentifiedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserDeidentifiedEvent::class)]
final class UserDeidentifiedEventTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $event = new UserDeidentifiedEvent('UID123', 42, 'TestNick');

        self::assertSame('UID123', $event->uid);
        self::assertSame(42, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
    }
}
