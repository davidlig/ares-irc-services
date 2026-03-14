<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use App\Infrastructure\IRC\ServiceBridge\ActiveChannelModeSupportProvider;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ActiveChannelModeSupportProvider::class)]
final class ActiveChannelModeSupportProviderTest extends TestCase
{
    #[Test]
    public function getSupportReturnsNullSupportWhenNoProtocolModule(): void
    {
        $holder = new ActiveConnectionHolder();
        $nullSupport = new NullChannelModeSupport();
        $provider = new ActiveChannelModeSupportProvider($holder, $nullSupport);

        $support = $provider->getSupport();

        self::assertSame($nullSupport, $support);
    }

    #[Test]
    public function getSupportReturnsModuleChannelModeSupportWhenModuleSet(): void
    {
        $holder = new ActiveConnectionHolder();
        $nullSupport = new NullChannelModeSupport();
        $moduleSupport = $this->createMock(ChannelModeSupportInterface::class);
        $module = $this->createMock(ProtocolModuleInterface::class);
        $module->method('getChannelModeSupport')->willReturn($moduleSupport);
        $holder->setProtocolModule($module);
        $provider = new ActiveChannelModeSupportProvider($holder, $nullSupport);

        $support = $provider->getSupport();

        self::assertSame($moduleSupport, $support);
    }
}
