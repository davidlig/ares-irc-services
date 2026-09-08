<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\In\Event\ActiveChannelModeSupportProvider;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        $moduleSupport = $this->createStub(ChannelModeSupportInterface::class);
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getChannelModeSupport')->willReturn($moduleSupport);
        $holder->setProtocolModule($module);
        $provider = new ActiveChannelModeSupportProvider($holder, $nullSupport);

        $support = $provider->getSupport();

        self::assertSame($moduleSupport, $support);
    }
}
