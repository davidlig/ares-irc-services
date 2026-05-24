<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\ValueObject;

use App\Domain\NickServ\ValueObject\NickStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickStatus::class)]
final class NickStatusTest extends TestCase
{
    #[Test]
    public function allCasesHaveExpectedValues(): void
    {
        self::assertSame('pending', NickStatus::Pending->value);
        self::assertSame('registered', NickStatus::Registered->value);
        self::assertSame('suspended', NickStatus::Suspended->value);
        self::assertSame('pending_deletion', NickStatus::PendingDeletion->value);
        self::assertSame('forbidden', NickStatus::Forbidden->value);
    }

    #[Test]
    public function allCasesCanBeUsedInSwitch(): void
    {
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
        self::assertCount(5, $results);
        self::assertSame('pending', $results['pending']);
        self::assertSame('registered', $results['registered']);
        self::assertSame('suspended', $results['suspended']);
        self::assertSame('pending_deletion', $results['pending_deletion']);
        self::assertSame('forbidden', $results['forbidden']);
    }
}
