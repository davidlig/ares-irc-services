<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Domain\ValueObject;

use App\NickServ\Domain\ValueObject\NickStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickStatus::class)]
final class NickStatusTest extends TestCase
{
    #[Test]
    public function allCasesHaveExpectedValues(): void
    {
        $expectedValues = [
            'Pending' => 'pending',
            'Registered' => 'registered',
            'Suspended' => 'suspended',
            'PendingDeletion' => 'pending_deletion',
            'Forbidden' => 'forbidden',
        ];

        foreach (NickStatus::cases() as $status) {
            self::assertSame($expectedValues[$status->name], $status->value);
        }
    }

    #[Test]
    public function allCasesCanBeUsedInSwitch(): void
    {
        /** @var array<string, string> $results */
        $results = [];
        foreach ([NickStatus::Pending, NickStatus::Registered, NickStatus::Suspended, NickStatus::PendingDeletion, NickStatus::Forbidden] as $status) {
            $results[$status->value] = match ($status) {
                NickStatus::Pending => 'pending',
                NickStatus::Registered => 'registered',
                NickStatus::Suspended => 'suspended',
                NickStatus::PendingDeletion => 'pending_deletion',
                NickStatus::Forbidden => 'forbidden',
            };
        }
        $expected = array_map(static fn (NickStatus $status): string => $status->value, NickStatus::cases());
        self::assertSame($expected, array_values($results));
    }
}
