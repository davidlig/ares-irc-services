<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Application\Port\ChannelModeSupportInterface;

/**
 * UnrealIRCd channel mode support.
 *
 * Based on docs/unreal/Channel_modes and linked docs.
 * Excluded from channel-setting / MLOCK: Prefix (v,h,o,a,q), List/ban (b, e, I), and +r.
 * Channel settings only: modes that configure the channel, not ranks nor ban/exempt/invex lists.
 */
final readonly class UnrealIRCdChannelModeSupport implements ChannelModeSupportInterface
{
    private const array PREFIX_MODES = ['v', 'h', 'o', 'a', 'q'];

    /** List/ban-related modes: b ban, e exempt, I invite exception. Excluded from channel setting and MLOCK. */
    private const array LIST_MODE_LETTERS = ['b', 'e', 'I'];

    /**
     * Channel setting modes unset with -X only (no parameter).
     * Excluded: r (never strip), list/ban (b,e,I), prefix (v,h,o,a,q), k,L,F,f,H (in UNSET_WITH_PARAM).
     */
    private const array CHANNEL_SETTING_UNSET_WITHOUT_PARAM = [
        'c', 'C', 'D', 'd', 'G', 'i', 'K', 'l', 'm', 'M', 'n', 'N', 'O', 'P', 'p', 'Q', 'R', 's', 'S', 'T', 't', 'V', 'z', 'Z',
    ];

    /**
     * Modes that need the current value to unset. Stored from burst/MODE so MLOCK can send -X value.
     * k key, L #channel (Channel_modes); F profile name (Channel_anti-flood_settings); f flood spec; H lines:minutes (Channel_history).
     */
    private const array CHANNEL_SETTING_UNSET_WITH_PARAM = ['k', 'L', 'F', 'f', 'H'];

    /**
     * Modes that take a parameter when set; consume in order when parsing MODE.
     * l (limit) param only on set; k, L, F, f, H stored for unset.
     */
    private const array CHANNEL_SETTING_WITH_PARAM_ON_SET = ['k', 'L', 'l', 'F', 'f', 'H'];

    public function hasVoice(): bool
    {
        return true;
    }

    public function hasHalfOp(): bool
    {
        return true;
    }

    public function hasOp(): bool
    {
        return true;
    }

    public function hasAdmin(): bool
    {
        return true;
    }

    public function hasOwner(): bool
    {
        return true;
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

    public function getChannelRegisteredModeLetter(): ?string
    {
        return 'r';
    }

    public function hasPermanentChannelMode(): bool
    {
        return true;
    }

    public function getPermanentChannelModeLetter(): ?string
    {
        return 'P';
    }
}
