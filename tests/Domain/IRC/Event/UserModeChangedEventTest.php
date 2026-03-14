<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserModeChangedEvent::class)]
final class UserModeChangedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $uid = new Uid('AAA111');
        $event = new UserModeChangedEvent($uid, '+r');

        self::assertSame($uid, $event->uid);
        self::assertSame('+r', $event->modeDelta);
    }
}
