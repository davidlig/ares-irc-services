<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Exposes channel mode support for the active IRCd (Unreal, InspIRCd, etc.).
 *
 * Used by ChanServ for:
 * - Prefix levels (v, h, o, a, q): LEVELS, OP, VOICE, etc.
 * - List/ban modes (b, e, I, and any protocol-specific list modes): excluded from MLOCK and from
 *   all "channel setting" lists (unset without/with param, with param on set).
 * - Channel setting modes unsettable without param: MLOCK can safely send -X for these
 *   when stripping modes not in the lock (e.g. -s for secret when MLOCK is +nt).
 *
 * Each protocol module provides an implementation so new IRCds only need to
 * implement this interface per their docs.
 *
 * @see https://www.unrealircd.org/docs/Channel_modes
 * @see https://docs.inspircd.org/4/channel-modes/
 */
interface ChannelModeSupportInterface
{
    public function hasVoice(): bool;

    public function hasHalfOp(): bool;

    public function hasOp(): bool;

    public function hasAdmin(): bool;

    public function hasOwner(): bool;

    /** @return list<string> Prefix mode letters (v, h, o, a, q) this IRCd supports */
    public function getSupportedPrefixModes(): array;

    /**
     * List mode letters (b, e, I). These take a mask parameter and must not
     * be treated as "channel setting" modes when stripping in MLOCK.
     * Case-sensitive: e.g. I = invite exception list, i = invite-only channel setting.
     *
     * @return list<string> Exact letters as on wire, e.g. ['b', 'e', 'I']
     */
    public function getListModeLetters(): array;

    /**
     * Channel setting mode letters that can be unset with -X only (no parameter).
     * MLOCK uses this to know which extra modes can be safely removed when
     * enforcing the lock (e.g. -s -m when MLOCK is +nt).
     *
     * @return list<string>
     */
    public function getChannelSettingModesUnsetWithoutParam(): array;

    /**
     * Channel setting mode letters that require the current value when unsetting
     * (e.g. -k password, -L #channel). MLOCK must pass these params when stripping;
     * the burst/MODE must store them so they are available.
     *
     * @return list<string> e.g. ['k', 'L'] for Unreal
     */
    public function getChannelSettingModesUnsetWithParam(): array;

    /**
     * Channel setting mode letters that take a parameter when set (and possibly when unset).
     * Used when parsing MODE to consume params in the correct order; only letters in
     * getChannelSettingModesUnsetWithParam() are stored for later -k/-L.
     *
     * @return list<string> e.g. ['k', 'L', 'l', 'F', 'f', 'H'] for Unreal
     */
    public function getChannelSettingModesWithParamOnSet(): array;

    /**
     * Whether this IRCd has the "channel is registered at Services" mode.
     * When ChanServ joins a registered channel, we set this mode so the channel shows as registered.
     * UnrealIRCd/InspIRCd: +r, distinct from +R (regonly = only registered users may join).
     *
     * @return bool True if the IRCd supports the channel-registered mode
     */
    public function hasChannelRegisteredMode(): bool;

    /**
     * The mode letter for the "channel is registered at Services" mode.
     * Null if the IRCd does not support this mode.
     * UnrealIRCd/InspIRCd: 'r'.
     *
     * @return string|null The mode letter (e.g., 'r'), or null if not supported
     */
    public function getChannelRegisteredModeLetter(): ?string;

    /**
     * Whether this IRCd has the "permanent channel" mode.
     * Permanent channels are not destroyed when the last user leaves.
     * UnrealIRCd: +P (chanmodes/permanent, IRCOp-only).
     * InspIRCd: +P (channel mode P, IRCOp-only).
     *
     * @return bool True if the IRCd supports the permanent channel mode
     */
    public function hasPermanentChannelMode(): bool;

    /**
     * The mode letter for the "permanent channel" mode.
     * Null if the IRCd does not support this mode.
     * UnrealIRCd/InspIRCd: 'P'.
     *
     * @return string|null The mode letter (e.g., 'P'), or null if not supported
     */
    public function getPermanentChannelModeLetter(): ?string;
}
