<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Event;

use App\Domain\IRC\Event\NickChangeReceivedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickChangeReceivedEvent::class)]
final class NickChangeReceivedEventTest extends TestCase
{
    #[Test]
    public function constructionAndProperties(): void
    {
        $event = new NickChangeReceivedEvent('UID123', 'NewNick');

        self::assertSame('UID123', $event->sourceId);
        self::assertSame('NewNick', $event->newNickStr);
    }
}
