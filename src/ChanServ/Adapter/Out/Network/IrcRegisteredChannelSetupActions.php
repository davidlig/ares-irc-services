<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelModeActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelSetupActions;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeChange;
use App\ChanServ\Domain\ValueObject\ModeChangeAction;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ServiceUidRegistry;

use function in_array;

final readonly class IrcRegisteredChannelSetupActions implements RegisteredChannelSetupActions
{
    /** @var list<string> */
    private const array PREFIX_ORDER = ['q', 'a', 'o', 'h', 'v'];

    public function __construct(
        private ChannelServiceActionsPort $networkActions,
        private ChannelModeActions $modeActions,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ServiceUidRegistry $uidRegistry,
    ) {}

    public function restoreRegistrationModes(string $channelName): void
    {
        $support = $this->modeSupportProvider->getSupport();
        $modes = array_values(array_filter([
            $support->getChannelRegisteredModeLetter(),
            $support->getPermanentChannelModeLetter(),
        ], static fn (?string $mode): bool => null !== $mode));

        if ([] !== $modes) {
            $this->networkActions->setChannelModes($channelName, '+' . implode('', $modes));
        }
    }

    public function restoreModeLock(string $channelName, ChannelModeLock $modeLock): void
    {
        if (!$modeLock->active || [] === $modeLock->settings) {
            return;
        }

        $withParameter = $this->modeSupportProvider->getSupport()->getChannelSettingModesWithParamOnSet();
        $changes = array_map(
            static fn (ChannelSetting $setting): ModeChange => new ModeChange(
                $setting->mode,
                ModeChangeAction::Add,
                in_array($setting->mode->value, $withParameter, true) ? $setting->parameter : null,
            ),
            $modeLock->settings,
        );

        $this->modeActions->apply($channelName, $changes);
    }

    public function restoreTopic(string $channelName, string $topic): void
    {
        $this->networkActions->setChannelTopic($channelName, $topic);
    }

    public function restoreServiceRank(string $channelName): void
    {
        $uid = $this->uidRegistry->getUid('chanserv');
        if (null === $uid) {
            return;
        }

        $supported = $this->modeSupportProvider->getSupport()->getSupportedPrefixModes();
        $rank = array_find(self::PREFIX_ORDER, static fn (string $mode): bool => in_array($mode, $supported, true)) ?? 'o';

        $this->networkActions->setChannelMemberMode($channelName, $uid, $rank, true);
    }
}
