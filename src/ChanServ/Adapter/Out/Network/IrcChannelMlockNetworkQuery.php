<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Model\ChannelMlockNetworkState;
use App\ChanServ\Application\Port\Out\ChannelMlockNetworkQuery;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeCapability;
use App\ChanServ\Domain\ValueObject\ModeName;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelView;

use function in_array;

final readonly class IrcChannelMlockNetworkQuery implements ChannelMlockNetworkQuery
{
    public function __construct(
        private ChannelLookupPort $channels,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private BurstCompletePort $burstComplete,
    ) {}

    public function findChannel(string $channelName): ?ChannelMlockNetworkState
    {
        $view = $this->channels->findByChannelName($channelName);
        if (null === $view) {
            return null;
        }

        $support = $this->modeSupportProvider->getSupport();

        return new ChannelMlockNetworkState(
            name: $view->name,
            settings: $this->settings($view, $support),
            modeCapabilities: $this->capabilities($support),
        );
    }

    public function synchronizationComplete(): bool
    {
        return $this->burstComplete->isComplete();
    }

    /** @return list<ChannelSetting> */
    private function settings(ChannelView $view, ChannelModeSupportInterface $support): array
    {
        $capabilities = [];
        foreach ($this->capabilities($support) as $capability) {
            $capabilities[$capability->mode->value] = true;
        }

        $settings = [];
        foreach (str_split($view->modes) as $letter) {
            if ('+' === $letter || '-' === $letter || !isset($capabilities[$letter])) {
                continue;
            }
            $settings[] = new ChannelSetting(new ModeName($letter), $view->getModeParam($letter));
        }

        return $settings;
    }

    /** @return list<ModeCapability> */
    private function capabilities(ChannelModeSupportInterface $support): array
    {
        $unsetWithout = $support->getChannelSettingModesUnsetWithoutParam();
        $unsetWith = $support->getChannelSettingModesUnsetWithParam();
        $setWith = $support->getChannelSettingModesWithParamOnSet();
        $names = array_values(array_unique([...$unsetWithout, ...$unsetWith, ...$setWith]));
        $protected = array_values(array_filter([
            $support->getChannelRegisteredModeLetter(),
            $support->getPermanentChannelModeLetter(),
        ], static fn (?string $letter): bool => null !== $letter));
        $names = array_values(array_unique([...$names, ...$protected]));

        return array_map(
            static fn (string $name): ModeCapability => new ModeCapability(
                mode: new ModeName($name),
                parameterRequiredWhenSet: in_array($name, $setWith, true),
                parameterRequiredWhenUnset: in_array($name, $unsetWith, true),
                protected: in_array($name, $protected, true),
            ),
            $names,
        );
    }
}
