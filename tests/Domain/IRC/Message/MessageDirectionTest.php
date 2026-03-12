<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Message;

use App\Domain\IRC\Message\MessageDirection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageDirection::class)]
final class MessageDirectionTest extends TestCase
{
    #[Test]
    public function incomingAndOutgoingCasesExist(): void
    {
        self::assertSame(MessageDirection::Incoming, MessageDirection::Incoming);
        self::assertSame(MessageDirection::Outgoing, MessageDirection::Outgoing);
    }
}
