<?php

declare(strict_types=1);

namespace App\Tests\Application\Command;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandOutcome::class)]
final class CommandOutcomeTest extends TestCase
{
    #[Test]
    public function successCarriesAuditData(): void
    {
        $auditData = new IrcopAuditData(target: 'target');

        $outcome = CommandOutcome::success($auditData);

        self::assertTrue($outcome->success);
        self::assertSame($auditData, $outcome->auditData);
    }

    #[Test]
    public function successMayOmitAuditData(): void
    {
        $outcome = CommandOutcome::success();

        self::assertTrue($outcome->success);
        self::assertNull($outcome->auditData);
    }

    #[Test]
    public function rejectedHasNoAuditData(): void
    {
        $outcome = CommandOutcome::rejected();

        self::assertFalse($outcome->success);
        self::assertNull($outcome->auditData);
    }
}
