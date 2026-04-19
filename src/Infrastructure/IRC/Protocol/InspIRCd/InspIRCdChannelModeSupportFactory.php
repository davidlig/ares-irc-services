<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use function array_key_exists;
use function in_array;

/**
 * Creates InspIRCdChannelModeSupport instances from CAPAB data or with default InspIRCd docs profile.
 *
 * The default profile represents a fully-loaded InspIRCd 4 server (all channel modes
 * from the documentation). This is used before the CAPAB is received; once the remote
 * server's actual CAPAB CHANMODES is parsed, createFromCapab() produces an instance
 * reflecting only what the remote server actually supports.
 *
 * @see https://docs.inspircd.org/4/channel-modes/
 */
final readonly class InspIRCdChannelModeSupportFactory
{
    private const array DEFAULT_PREFIX_MODES = ['v', 'h', 'o', 'a', 'q'];

    private const array DEFAULT_LIST_MODE_LETTERS = ['b', 'e', 'I', 'g', 'w', 'X', 'Z'];

    private const array DEFAULT_CHANNEL_SETTING_UNSET_WITHOUT_PARAM = [
        'i', 'm', 'n', 'p', 's', 't',
        'A', 'c', 'C', 'D', 'K', 'M', 'N', 'O', 'P', 'Q', 'r', 'R', 'S', 'T', 'u', 'U', 'z',
        'l', 'B', 'd', 'E', 'f', 'F', 'H', 'J', 'j', 'L',
    ];

    private const array DEFAULT_CHANNEL_SETTING_UNSET_WITH_PARAM = ['k'];

    private const array DEFAULT_CHANNEL_SETTING_WITH_PARAM_ON_SET = ['k', 'l', 'B', 'd', 'E', 'f', 'F', 'H', 'J', 'j', 'L'];

    private const string DEFAULT_PERMANENT_MODE_LETTER = 'P';

    private const string DEFAULT_REGISTERED_MODE_LETTER = 'r';

    private const array PREFIX_NAME_TO_LETTER = [
        'voice' => 'v',
        'halfop' => 'h',
        'op' => 'o',
        'admin' => 'a',
        'founder' => 'q',
        'owner' => 'q',
    ];

    private const array PARAM_SET_MODES_UNSET_WITHOUT_PARAM = ['l', 'B', 'd', 'E', 'f', 'F', 'H', 'J', 'j', 'L'];

    public function createDefault(): InspIRCdChannelModeSupport
    {
        return new InspIRCdChannelModeSupport(
            prefixModes: self::DEFAULT_PREFIX_MODES,
            listModeLetters: self::DEFAULT_LIST_MODE_LETTERS,
            channelSettingUnsetWithoutParam: self::DEFAULT_CHANNEL_SETTING_UNSET_WITHOUT_PARAM,
            channelSettingUnsetWithParam: self::DEFAULT_CHANNEL_SETTING_UNSET_WITH_PARAM,
            channelSettingWithParamOnSet: self::DEFAULT_CHANNEL_SETTING_WITH_PARAM_ON_SET,
            hasHalfOp: true,
            hasAdmin: true,
            hasOwner: true,
            hasPermanentMode: true,
            permanentModeLetter: self::DEFAULT_PERMANENT_MODE_LETTER,
            hasRegisteredMode: true,
            registeredModeLetter: self::DEFAULT_REGISTERED_MODE_LETTER,
        );
    }

    /**
     * Creates an InspIRCdChannelModeSupport from the CAPAB data received from the remote server.
     *
     * Rules:
     * - Prefix modes: derived from CHANMODES prefix entries (voice/halfop/op/admin/founder/owner).
     * - Has methods: derived from prefix names (hasHalfOp ← halfop, hasAdmin ← admin, hasOwner ← founder/owner).
     * - Permanent mode (+P): derived from CHANMODES simple modes.
     * - Registered mode (+r): derived from CHANMODES simple modes.
     * - List modes: directly from CHANMODES list entries.
     * - Channel-setting categories: simple modes go to unset-without-param;
     *   param-set modes are split between unset-without-param (l, B, f, etc.)
     *   and unset-with-param (k only).
     */
    public function createFromCapab(InspIRCdCapab $capab): InspIRCdChannelModeSupport
    {
        $prefixModes = $this->buildPrefixModes($capab);
        $listModes = $capab->getListModes();
        $paramSetModes = $capab->getParamSetModes();
        $simpleModes = $capab->getSimpleModes();

        $hasHalfOp = $capab->hasPrefixMode('halfop');
        $hasAdmin = $capab->hasPrefixMode('admin');
        $hasOwner = $capab->hasPrefixMode('founder') || $capab->hasPrefixMode('owner');

        $hasPermanent = in_array('P', $simpleModes, true);
        $permanentLetter = $hasPermanent ? 'P' : null;

        $hasRegistered = in_array('r', $simpleModes, true);
        $registeredLetter = $hasRegistered ? 'r' : null;

        $channelSettingUnsetWithoutParam = $this->buildChannelSettingUnsetWithoutParam($simpleModes, $paramSetModes, $listModes, $prefixModes);
        $channelSettingUnsetWithParam = $this->buildChannelSettingUnsetWithParam($paramSetModes, $listModes);
        $channelSettingWithParamOnSet = $this->buildChannelSettingWithParamOnSet($paramSetModes, $listModes);

        return new InspIRCdChannelModeSupport(
            prefixModes: $prefixModes,
            listModeLetters: $listModes,
            channelSettingUnsetWithoutParam: $channelSettingUnsetWithoutParam,
            channelSettingUnsetWithParam: $channelSettingUnsetWithParam,
            channelSettingWithParamOnSet: $channelSettingWithParamOnSet,
            hasHalfOp: $hasHalfOp,
            hasAdmin: $hasAdmin,
            hasOwner: $hasOwner,
            hasPermanentMode: $hasPermanent,
            permanentModeLetter: $permanentLetter,
            hasRegisteredMode: $hasRegistered,
            registeredModeLetter: $registeredLetter,
        );
    }

    /**
     * Build prefix modes from CAPAB prefix entries, sorted by level ascending.
     *
     * @return list<string>
     */
    private function buildPrefixModes(InspIRCdCapab $capab): array
    {
        $capabPrefixes = $capab->getPrefixModes();
        $letterLevels = [];

        foreach ($capabPrefixes as $name => $level) {
            $letter = self::PREFIX_NAME_TO_LETTER[strtolower($name)] ?? null;
            if (null !== $letter && !array_key_exists($letter, $letterLevels)) {
                $letterLevels[$letter] = $level;
            }
        }

        uksort($letterLevels, static fn (string $a, string $b): int => $letterLevels[$a] <=> $letterLevels[$b]);

        return array_keys($letterLevels);
    }

    /**
     * Build channel-setting modes unset without param from CAPAB.
     *
     * Includes: all simple modes + param-set modes that are NOT 'k' (key needs param to unset).
     * Excludes: list modes and prefix modes.
     *
     * @param list<string> $simpleModes
     * @param list<string> $paramSetModes
     * @param list<string> $listModes
     * @param list<string> $prefixModes
     *
     * @return list<string>
     */
    private function buildChannelSettingUnsetWithoutParam(array $simpleModes, array $paramSetModes, array $listModes, array $prefixModes): array
    {
        $result = [];

        foreach ($simpleModes as $letter) {
            if (in_array($letter, $listModes, true) || in_array($letter, $prefixModes, true)) {
                continue;
            }

            $result[] = $letter;
        }

        foreach ($paramSetModes as $letter) {
            if (in_array($letter, $listModes, true) || in_array($letter, $prefixModes, true)) {
                continue;
            }

            if (in_array($letter, $result, true)) {
                continue;
            }

            if (!in_array($letter, self::PARAM_SET_MODES_UNSET_WITHOUT_PARAM, true)) {
                continue;
            }

            $result[] = $letter;
        }

        return $result;
    }

    /**
     * Build channel-setting modes unset with param from CAPAB.
     *
     * Only param-set mode 'k' needs the current value to unset (-k password).
     *
     * @param list<string> $paramSetModes
     * @param list<string> $listModes
     *
     * @return list<string>
     */
    private function buildChannelSettingUnsetWithParam(array $paramSetModes, array $listModes): array
    {
        $result = [];

        foreach ($paramSetModes as $letter) {
            if (in_array($letter, $listModes, true)) {
                continue;
            }

            if ('k' === $letter) {
                $result[] = $letter;
            }
        }

        return $result;
    }

    /**
     * Build channel-setting modes with param on set from CAPAB.
     *
     * All param-set modes that are not list modes.
     *
     * @param list<string> $paramSetModes
     * @param list<string> $listModes
     *
     * @return list<string>
     */
    private function buildChannelSettingWithParamOnSet(array $paramSetModes, array $listModes): array
    {
        $result = [];

        foreach ($paramSetModes as $letter) {
            if (in_array($letter, $listModes, true)) {
                continue;
            }

            $result[] = $letter;
        }

        return $result;
    }
}
