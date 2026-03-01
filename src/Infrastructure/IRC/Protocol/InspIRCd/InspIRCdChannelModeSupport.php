<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ChannelModeSupportInterface;

/**
 * InspIRCd channel mode support.
 *
 * Based on https://docs.inspircd.org/4/channel-modes/.
 * Excluded from channel-setting / MLOCK: Prefix (o, v, y, Y) and List/ban (b, e, I, g, w, X, Z).
 * Channel settings only: not ranks nor ban/exempt/invex/filter/autoop lists.
 */
final readonly class InspIRCdChannelModeSupport implements ChannelModeSupportInterface
{
    private const array PREFIX_MODES = ['v', 'o'];

    /** List/ban-related: b ban, e exempt, I invex; g filter, w autoop, X exemptchanops, Z namebase. Excluded from MLOCK. */
    private const array LIST_MODE_LETTERS = ['b', 'e', 'I', 'g', 'w', 'X', 'Z'];

    /**
     * Channel setting modes unset with -X only (no parameter). Switch + Parameter types.
     * Excluded: list/ban (b,e,I,g,w,X,Z), prefix (o,v,y,Y), ParamBoth (k) → in UNSET_WITH_PARAM.
     */
    private const array CHANNEL_SETTING_UNSET_WITHOUT_PARAM = [
        'i', 'm', 'n', 'p', 's', 't',
        'A', 'c', 'C', 'D', 'K', 'M', 'N', 'O', 'P', 'Q', 'r', 'R', 'S', 'T', 'u', 'U', 'z',
        'l', 'B', 'd', 'E', 'f', 'F', 'H', 'J', 'j', 'L',
    ];

    /** ParamBoth: k needs key to unset (-k cheddar). Stored from MODE for MLOCK. */
    private const array CHANNEL_SETTING_UNSET_WITH_PARAM = ['k'];

    /**
     * Modes that take a param when set; consume in order when parsing MODE.
     * Core: k, l. Modules: B, d, E, f, F, H, J, j, L. Excludes List and Prefix (o, v, y, Y).
     */
    private const array CHANNEL_SETTING_WITH_PARAM_ON_SET = ['k', 'l', 'B', 'd', 'E', 'f', 'F', 'H', 'J', 'j', 'L'];

    public function hasVoice(): bool
    {
        return true;
    }

    public function hasHalfOp(): bool
    {
        return false;
    }

    public function hasOp(): bool
    {
        return true;
    }

    public function hasAdmin(): bool
    {
        return false;
    }

    public function hasOwner(): bool
    {
        return false;
    }

    /** @return list<string> */
    public function getSupportedPrefixModes(): array
    {
        return self::PREFIX_MODES;
    }

    /** @return list<string> */
    public function getListModeLetters(): array
    {
        return self::LIST_MODE_LETTERS;
    }

    /** @return list<string> */
    public function getChannelSettingModesUnsetWithoutParam(): array
    {
        return self::CHANNEL_SETTING_UNSET_WITHOUT_PARAM;
    }

    /** @return list<string> */
    public function getChannelSettingModesUnsetWithParam(): array
    {
        return self::CHANNEL_SETTING_UNSET_WITH_PARAM;
    }

    /** @return list<string> */
    public function getChannelSettingModesWithParamOnSet(): array
    {
        return self::CHANNEL_SETTING_WITH_PARAM_ON_SET;
    }

    public function hasChannelRegisteredMode(): bool
    {
        return true;
    }
}
