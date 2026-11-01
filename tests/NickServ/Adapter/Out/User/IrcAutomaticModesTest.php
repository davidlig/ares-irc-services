<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\Irc\Application\Port\In\AutomaticModeDelivery;
use App\Irc\Application\Port\In\AutomaticModeSupport;
use App\NickServ\Adapter\Out\User\IrcAutomaticModes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcAutomaticModes::class)]
final class IrcAutomaticModesTest extends TestCase
{
    public function testUsesTheSelectedPublicCapabilityAndDeliveryBoundaries(): void
    {
        $support = $this->createStub(AutomaticModeSupport::class);
        $support->method('getAutomaticModes')->willReturn(['bot' => 'B']);
        $delivery = $this->createMock(AutomaticModeDelivery::class);
        $delivery->method('usesNativeProfiles')->willReturn(true);
        $delivery->expects(self::once())->method('changeUserModes')->with('UID', '+B')->willReturn(false);
        $adapter = new IrcAutomaticModes($support, $delivery);
        self::assertSame(['bot' => 'B'], $adapter->available());
        self::assertTrue($adapter->usesNativeProfiles());
        self::assertFalse($adapter->change('UID', '+B'));
    }
}
