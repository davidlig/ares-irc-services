<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Event\NickIdentifiedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickIdentifiedEvent::class)]
final class NickIdentifiedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $event = new NickIdentifiedEvent(42, 'MyNick', '001ABCD');

        self::assertSame(42, $event->nickId);
        self::assertSame('MyNick', $event->nickname);
        self::assertSame('001ABCD', $event->uid);
    }
}
