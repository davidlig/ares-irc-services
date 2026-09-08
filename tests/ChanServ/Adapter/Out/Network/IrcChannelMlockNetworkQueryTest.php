<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcChannelMlockNetworkQuery;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelMlockNetworkQuery::class)]
final class IrcChannelMlockNetworkQueryTest extends TestCase
{
    #[Test]
    public function itReturnsNullWhenTheNetworkChannelDoesNotExist(): void
    {
        $channels = $this->createStub(ChannelLookupPort::class);
        $channels->method('findByChannelName')->willReturn(null);

        self::assertNull($this->query($channels)->findChannel('#missing'));
    }

    #[Test]
    public function itMapsOnlySettingsAndModeCapabilities(): void
    {
        $channels = $this->createStub(ChannelLookupPort::class);
        $channels->method('findByChannelName')->willReturn(new ChannelView(
            name: '#Case',
            modes: '+nkKrPbI',
            topic: null,
            memberCount: 1,
            members: [['uid' => 'U1', 'roleLetter' => 'o']],
            modeParams: ['k' => 'secret', 'K' => 'UPPER'],
        ));

        $support = $this->createMock(ChannelModeSupportInterface::class);
        $support->expects(self::never())->method('getSupportedPrefixModes');
        $support->method('getChannelSettingModesUnsetWithoutParam')->willReturn(['n', 'R']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn(['k', 'L']);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l', 'K']);
        $support->method('getChannelRegisteredModeLetter')->willReturn('r');
        $support->method('getPermanentChannelModeLetter')->willReturn('P');
        $supportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $supportProvider->method('getSupport')->willReturn($support);

        $result = new IrcChannelMlockNetworkQuery(
            $channels,
            $supportProvider,
            $this->createStub(BurstCompletePort::class),
        )->findChannel('#Case');

        self::assertNotNull($result);
        self::assertSame('#Case', $result->name);
        self::assertSame(
            [
                ['n', null],
                ['k', 'secret'],
                ['K', 'UPPER'],
                ['r', null],
                ['P', null],
            ],
            array_map(static fn ($setting): array => [$setting->mode->value, $setting->parameter], $result->settings),
        );
        self::assertSame(
            [
                ['n', false, false, false],
                ['R', false, false, false],
                ['k', true, true, false],
                ['L', false, true, false],
                ['l', true, false, false],
                ['K', true, false, false],
                ['r', false, false, true],
                ['P', false, false, true],
            ],
            array_map(
                static fn ($capability): array => [
                    $capability->mode->value,
                    $capability->parameterRequiredWhenSet,
                    $capability->parameterRequiredWhenUnset,
                    $capability->protected,
                ],
                $result->modeCapabilities,
            ),
        );
    }

    #[Test]
    public function itDelegatesSynchronizationState(): void
    {
        $burst = $this->createStub(BurstCompletePort::class);
        $burst->method('isComplete')->willReturn(true);
        $query = new IrcChannelMlockNetworkQuery(
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $burst,
        );

        self::assertTrue($query->synchronizationComplete());
    }

    private function query(ChannelLookupPort $channels): IrcChannelMlockNetworkQuery
    {
        return new IrcChannelMlockNetworkQuery(
            $channels,
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(BurstCompletePort::class),
        );
    }
}
