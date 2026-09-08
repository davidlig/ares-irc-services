<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\Application\Port\ChannelModeSupportInterface;
use App\ChanServ\Adapter\In\Irc\MlockStateFromChannelResolver;
use App\Irc\Application\Port\In\ChannelView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MlockStateFromChannelResolver::class)]
final class MlockStateFromChannelResolverTest extends TestCase
{
    #[Test]
    public function itReturnsAnEmptyLockForAChannelWithoutModes(): void
    {
        $resolver = new MlockStateFromChannelResolver();

        $lock = $resolver->resolve(
            new ChannelView('#test', '', null, 0),
            $this->support(),
        );

        self::assertTrue($lock->active);
        self::assertSame([], $lock->settings);
    }

    #[Test]
    public function itKeepsSupportedSettingModesAndTheirParameters(): void
    {
        $resolver = new MlockStateFromChannelResolver();
        $view = new ChannelView(
            '#test',
            '+ntkroPvnM',
            null,
            0,
            modeParams: ['k' => 'secret'],
        );

        $lock = $resolver->resolve($view, $this->support());

        self::assertSame(['n', 't', 'k', 'M'], array_map(static fn ($setting): string => $setting->mode->value, $lock->settings));
        self::assertSame([null, null, 'secret', null], array_map(static fn ($setting): ?string => $setting->parameter, $lock->settings));
    }

    #[Test]
    public function itOmitsAParameterWhenTheCurrentModeHasNone(): void
    {
        $resolver = new MlockStateFromChannelResolver();

        $lock = $resolver->resolve(
            new ChannelView('#test', '+k', null, 0, modeParams: ['k' => '']),
            $this->support(),
        );

        self::assertSame('k', $lock->settings[0]->mode->value);
        self::assertNull($lock->settings[0]->parameter);
    }

    private function support(): ChannelModeSupportInterface
    {
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelSettingModesUnsetWithoutParam')->willReturn(['n', 't', 'M']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn(['k']);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k']);
        $support->method('getPermanentChannelModeLetter')->willReturn('P');

        return $support;
    }
}
