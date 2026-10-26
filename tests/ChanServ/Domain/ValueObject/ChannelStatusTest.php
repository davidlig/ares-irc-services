<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\ValueObject;

use App\ChanServ\Domain\ValueObject\ChannelStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelStatus::class)]
final class ChannelStatusTest extends TestCase
{
    #[Test]
    public function activeHasCorrectValue(): void
    {
        $value = 'ACTIVE';

        self::assertSame(ChannelStatus::Active, self::parseValue(strtolower($value)));
    }

    #[Test]
    public function suspendedHasCorrectValue(): void
    {
        $value = 'SUSPENDED';

        self::assertSame(ChannelStatus::Suspended, self::parseValue(strtolower($value)));
    }

    #[Test]
    public function allCasesAreExhaustiveInMatch(): void
    {
        /** @var array<string, string> $results */
        $results = [];
        foreach (ChannelStatus::cases() as $status) {
            $results[$status->value] = match ($status) {
                ChannelStatus::Active => 'active',
                ChannelStatus::Suspended => 'suspended',
                ChannelStatus::PendingDeletion => 'pending_deletion',
                ChannelStatus::Forbidden => 'forbidden',
                ChannelStatus::Pending => 'pending',
                ChannelStatus::Rejected => 'rejected',
            };
        }

        $expected = array_map(static fn (ChannelStatus $status): string => $status->value, ChannelStatus::cases());
        self::assertSame($expected, array_values($results));
    }

    #[Test]
    public function pendingDeletionHasCorrectValue(): void
    {
        $value = 'PENDING_DELETION';

        self::assertSame(ChannelStatus::PendingDeletion, self::parseValue(strtolower($value)));
    }

    #[Test]
    public function forbiddenHasCorrectValue(): void
    {
        $value = 'FORBIDDEN';

        self::assertSame(ChannelStatus::Forbidden, self::parseValue(strtolower($value)));
    }

    #[Test]
    public function approvalStatusesHaveStableValues(): void
    {
        self::assertSame(ChannelStatus::Pending, self::parseValue('pending'));
        self::assertSame(ChannelStatus::Rejected, self::parseValue('rejected'));
    }

    private static function parseValue(string $value): ChannelStatus
    {
        return ChannelStatus::from($value);
    }
}
