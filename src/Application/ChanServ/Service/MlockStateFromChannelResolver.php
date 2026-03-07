<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Service;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;

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
     * Excludes only lowercase +r (channel registered at services). Only includes
     * channel-setting modes allowed by support. Case is preserved (e.g. +M, +R stay as-is).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public function resolve(ChannelView $view, ChannelModeSupportInterface $support): array
    {
        if ('' === $view->modes) {
            return ['', []];
        }

        $unsetWithout = $support->getChannelSettingModesUnsetWithoutParam();
        $unsetWith = $support->getChannelSettingModesUnsetWithParam();
        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();
        $allowedLetters = array_flip(array_merge($unsetWithout, $unsetWith, $withParamOnSet));

        $letters = [];
        $params = [];
        foreach (str_split($view->modes) as $c) {
            if ('+' === $c || '-' === $c) {
                continue;
            }
            // Only skip lowercase r (channel registered); do not skip +R (regonly) or alter case
            if ('r' === $c) {
                continue;
            }
            if (!isset($allowedLetters[$c])) {
                continue;
            }
            $letters[] = $c;
            if (in_array($c, $withParamOnSet, true)) {
                $param = $view->getModeParam($c);
                if (null !== $param && '' !== $param) {
                    $params[$c] = $param;
                }
            }
        }

        $modeString = [] === $letters ? '' : '+' . implode('', array_unique($letters));

        return [$modeString, $params];
    }
}
