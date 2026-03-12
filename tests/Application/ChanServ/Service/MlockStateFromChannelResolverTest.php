<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Service;

use App\Application\ChanServ\Service\MlockStateFromChannelResolver;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MlockStateFromChannelResolver::class)]
final class MlockStateFromChannelResolverTest extends TestCase
{
    #[Test]
    public function resolveReturnsEmptyWhenModesEmpty(): void
    {
        $view = new ChannelView('#test', '', null, 0);
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelSettingModesUnsetWithoutParam')->willReturn([]);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn([]);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $resolver = new MlockStateFromChannelResolver();
        [$modeString, $params] = $resolver->resolve($view, $support);

        self::assertSame('', $modeString);
        self::assertSame([], $params);
    }

    #[Test]
    public function resolveExcludesLowercaseR(): void
    {
        $view = new ChannelView('#test', '+rnt', null, 0);
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelSettingModesUnsetWithoutParam')->willReturn(['n', 't', 'r']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn([]);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn([]);

        $resolver = new MlockStateFromChannelResolver();
        [$modeString, $params] = $resolver->resolve($view, $support);

        self::assertSame('+nt', $modeString);
        self::assertSame([], $params);
    }

    #[Test]
    public function resolveIncludesParamsForModesWithParamOnSet(): void
    {
        $view = new ChannelView('#test', '+k', null, 0, [], 0, ['k' => 'secret']);
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelSettingModesUnsetWithoutParam')->willReturn([]);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn(['k']);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k']);

        $resolver = new MlockStateFromChannelResolver();
        [$modeString, $params] = $resolver->resolve($view, $support);

        self::assertSame('+k', $modeString);
        self::assertSame(['k' => 'secret'], $params);
    }
}
