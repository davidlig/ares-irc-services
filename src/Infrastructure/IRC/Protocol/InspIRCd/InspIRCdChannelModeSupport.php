<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Application\Port\ChannelModeSupportInterface;

use function in_array;

/**
 * InspIRCd channel mode support.
 *
 * Based on https://docs.inspircd.org/4/channel-modes/.
 * Excluded from channel-setting / MLOCK: Prefix (o, v, etc.) and List/ban (b, e, I, etc.).
 * Channel settings only: not ranks nor ban/exempt/invex/filter/autoop lists.
 *
 * Can be created with factory defaults (full InspIRCd docs profile) or dynamically
 * from the CAPAB CHANMODES payload received from the remote server.
 *
 * @see InspIRCdChannelModeSupportFactory
 */
final readonly class InspIRCdChannelModeSupport implements ChannelModeSupportInterface
{
    /**
     * @param list<string> $prefixModes                     Prefix mode letters sorted lowest→highest rank (e.g. ['v','h','o','a','q'])
     * @param list<string> $listModeLetters                 List/ban mode letters (excluded from MLOCK)
     * @param list<string> $channelSettingUnsetWithoutParam Channel setting modes unset without param
     * @param list<string> $channelSettingUnsetWithParam    Channel setting modes requiring param to unset
     * @param list<string> $channelSettingWithParamOnSet    Channel setting modes requiring param on set
     * @param bool         $hasHalfOp                       Whether +h (halfop) is available
     * @param bool         $hasAdmin                        Whether +a (admin/protect) is available
     * @param bool         $hasOwner                        Whether +q (founder/owner) is available
     * @param bool         $hasPermanentMode                Whether +P (permanent channel) is available
     * @param string|null  $permanentModeLetter             Letter for permanent mode (e.g. 'P'), null if not supported
     * @param bool         $hasRegisteredMode               Whether +r (channel registered) is available
     * @param string|null  $registeredModeLetter            Letter for registered mode (e.g. 'r'), null if not supported
     */
    public function __construct(
        private array $prefixModes,
        private array $listModeLetters,
        private array $channelSettingUnsetWithoutParam,
        private array $channelSettingUnsetWithParam,
        private array $channelSettingWithParamOnSet,
        private bool $hasHalfOp,
        private bool $hasAdmin,
        private bool $hasOwner,
        private bool $hasPermanentMode,
        private ?string $permanentModeLetter,
        private bool $hasRegisteredMode,
        private ?string $registeredModeLetter,
    ) {
    }

    public function hasVoice(): bool
    {
        return in_array('v', $this->prefixModes, true);
    }

    public function hasHalfOp(): bool
    {
        return $this->hasHalfOp;
    }

    public function hasOp(): bool
    {
        return in_array('o', $this->prefixModes, true);
    }

    public function hasAdmin(): bool
    {
        return $this->hasAdmin;
    }

    public function hasOwner(): bool
    {
        return $this->hasOwner;
    }

    /** @return list<string> */
    public function getSupportedPrefixModes(): array
    {
        return $this->prefixModes;
    }

    /** @return list<string> */
    public function getListModeLetters(): array
    {
        return $this->listModeLetters;
    }

    /** @return list<string> */
    public function getChannelSettingModesUnsetWithoutParam(): array
    {
        return $this->channelSettingUnsetWithoutParam;
    }

    /** @return list<string> */
    public function getChannelSettingModesUnsetWithParam(): array
    {
        return $this->channelSettingUnsetWithParam;
    }

    /** @return list<string> */
    public function getChannelSettingModesWithParamOnSet(): array
    {
        return $this->channelSettingWithParamOnSet;
    }

    public function hasChannelRegisteredMode(): bool
    {
        return $this->hasRegisteredMode;
    }

    public function getChannelRegisteredModeLetter(): ?string
    {
        return $this->registeredModeLetter;
    }

    public function hasPermanentChannelMode(): bool
    {
        return $this->hasPermanentMode;
    }

    public function getPermanentChannelModeLetter(): ?string
    {
        return $this->permanentModeLetter;
    }
}
