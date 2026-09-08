<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\Application\Port\ChannelModeSupportInterface;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeName;
use App\Irc\Application\Port\In\ChannelView;

use function array_flip;
use function array_merge;
use function in_array;
use function str_split;

/**
 * Resolves MLOCK mode string and params from the current channel view.
 * Used when turning MLOCK ON (SET) and when initializing empty MLOCK on sync.
 *
 * Mode letters are case-sensitive (+M ≠ +m). We preserve the exact case from the view;
 * no conversion to lower or upper case is applied.
 */
final readonly class MlockStateFromChannelResolver
{
    /**
     * Returns [modeString, params] for MLOCK from the channel's current modes.
     * Excludes +r (channel registered) and +P (permanent channel), both are service-controlled.
     * Only includes channel-setting modes allowed by support. Case is preserved (e.g. +M, +R).
     */
    public function resolve(ChannelView $view, ChannelModeSupportInterface $support): ChannelModeLock
    {
        if ('' === $view->modes) {
            return ChannelModeLock::active();
        }

        $unsetWithout = $support->getChannelSettingModesUnsetWithoutParam();
        $unsetWith = $support->getChannelSettingModesUnsetWithParam();
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $allowedLetters = array_flip(array_merge($unsetWithout, $unsetWith, $withParamOnSet));
        $permanentLetter = $support->getPermanentChannelModeLetter();

        $settings = [];
        foreach (str_split($view->modes) as $c) {
            if ('+' === $c || '-' === $c) {
                continue;
            }
            // Skip +r (channel registered) and +P (permanent) - both are service-controlled
            if ('r' === $c || $c === $permanentLetter) {
                continue;
            }
            if (!isset($allowedLetters[$c])) {
                continue;
            }
            $parameter = in_array($c, $withParamOnSet, true) ? $view->getModeParam($c) : null;
            $settings[] = new ChannelSetting(
                new ModeName($c),
                null !== $parameter && '' !== $parameter ? $parameter : null,
            );
        }

        return ChannelModeLock::active($settings);
    }
}
