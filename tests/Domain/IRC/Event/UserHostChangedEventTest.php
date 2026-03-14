<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\UserHostChangedEvent;
use App\Domain\IRC\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserHostChangedEvent::class)]
final class UserHostChangedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $uid = new Uid('AAA111');
        $event = new UserHostChangedEvent($uid, 'newvhost.example.com');

        self::assertSame($uid, $event->uid);
        self::assertSame('newvhost.example.com', $event->newHost);
    }
}
