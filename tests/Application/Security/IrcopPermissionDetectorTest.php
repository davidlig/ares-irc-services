<?php

declare(strict_types=1);

namespace App\Tests\Application\Security;

use App\Application\Security\IrcopPermissionDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopPermissionDetector::class)]
final class IrcopPermissionDetectorTest extends TestCase
{
    private IrcopPermissionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new IrcopPermissionDetector();
    }

    #[Test]
    public function isIrcopPermissionReturnsTrueForOperServKill(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.kill'));
    }

    #[Test]
    public function isIrcopPermissionReturnsTrueForOperServGline(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.gline'));
    }

    #[Test]
    public function isIrcopPermissionReturnsTrueForOperServAdminAdd(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.admin.add'));
    }

    #[Test]
    public function isIrcopPermissionReturnsTrueForNickServDrop(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('nickserv.drop'));
    }

    #[Test]
    public function isIrcopPermissionReturnsTrueForChanServSuspend(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('chanserv.suspend'));
    }

    #[Test]
    public function isIrcopPermissionReturnsFalseForIdentified(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('IDENTIFIED'));
    }

    #[Test]
    public function isIrcopPermissionReturnsFalseForUppercasePermission(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('OPERSERV.KILL'));
    }

    #[Test]
    public function isIrcopPermissionReturnsFalseForMixedCasePermission(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('OperServ.Kill'));
    }

    #[Test]
    public function isIrcopPermissionReturnsFalseForPermissionWithoutDot(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('operserv'));
    }

    #[Test]
    public function isIrcopPermissionReturnsFalseForPermissionWithMultipleDots(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.admin.role.add'));
    }

    #[Test]
    public function isIrcopPermissionReturnsFalseForEmptyString(): void
    {
        self::assertFalse($this->detector->isIrcopPermission(''));
    }

    #[Test]
    public function isIrcopPermissionReturnsFalseForPermissionWithUnderscore(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.my_permission'));
    }
}
