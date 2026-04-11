<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\ValueObject;

use App\Domain\ChanServ\ValueObject\ChannelStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelStatus::class)]
final class ChannelStatusTest extends TestCase
{
    #[Test]
    public function activeHasCorrectValue(): void
    {
        self::assertSame('active', ChannelStatus::Active->value);
    }

    #[Test]
    public function suspendedHasCorrectValue(): void
    {
        self::assertSame('suspended', ChannelStatus::Suspended->value);
    }

    #[Test]
    public function allCasesAreExhaustiveInMatch(): void
    {
        $results = [];
        foreach (ChannelStatus::cases() as $status) {
            $results[$status->value] = match ($status) {
                ChannelStatus::Active => 'active',
                ChannelStatus::Suspended => 'suspended',
            };
        }

        self::assertCount(2, $results);
        self::assertSame('active', $results['active']);
        self::assertSame('suspended', $results['suspended']);
    }
}
