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
        $caseNames = array_map(
            static fn (MessageDirection $direction): string => $direction->name,
            MessageDirection::cases(),
        );

        $uniqueCaseNames = array_values(array_unique($caseNames));

        self::assertCount(2, $uniqueCaseNames);
        self::assertContains('Incoming', $uniqueCaseNames);
        self::assertContains('Outgoing', $uniqueCaseNames);
    }
}
