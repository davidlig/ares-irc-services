<?php

declare(strict_types=1);

namespace App\Tests\Application\Security;

use App\Application\Security\IrcopPermissionDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopPermissionDetector::class)]
final class IrcopPermissionDetectorTest extends TestCase
{
    private IrcopPermissionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new IrcopPermissionDetector();
    }

    public function testIsIrcopPermissionReturnsTrueForOperServKill(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.kill'));
    }

    public function testIsIrcopPermissionReturnsTrueForOperServGline(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.gline'));
    }

    public function testIsIrcopPermissionReturnsTrueForOperServAdminAdd(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.admin.add'));
    }

    public function testIsIrcopPermissionReturnsTrueForNickServDrop(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('nickserv.drop'));
    }

    public function testIsIrcopPermissionReturnsTrueForChanServSuspend(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('chanserv.suspend'));
    }

    public function testIsIrcopPermissionReturnsFalseForIdentified(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('IDENTIFIED'));
    }

    public function testIsIrcopPermissionReturnsFalseForUppercasePermission(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('OPERSERV.KILL'));
    }

    public function testIsIrcopPermissionReturnsFalseForMixedCasePermission(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('OperServ.Kill'));
    }

    public function testIsIrcopPermissionReturnsFalseForPermissionWithoutDot(): void
    {
        self::assertFalse($this->detector->isIrcopPermission('operserv'));
    }

    public function testIsIrcopPermissionReturnsFalseForPermissionWithMultipleDots(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.admin.role.add'));
    }

    public function testIsIrcopPermissionReturnsFalseForEmptyString(): void
    {
        self::assertFalse($this->detector->isIrcopPermission(''));
    }

    public function testIsIrcopPermissionReturnsFalseForPermissionWithUnderscore(): void
    {
        self::assertTrue($this->detector->isIrcopPermission('operserv.my_permission'));
    }
}
