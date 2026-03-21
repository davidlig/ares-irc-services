<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol;

use App\Application\Port\ChannelModeSupportInterface;

/**
 * No channel prefix modes supported (e.g. when no protocol module is active).
 */
final readonly class NullChannelModeSupport implements ChannelModeSupportInterface
{
    public function hasVoice(): bool
    {
        return false;
    }

    public function hasHalfOp(): bool
    {
        return false;
    }

    public function hasOp(): bool
    {
        return false;
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
        return [];
    }

    /** @return list<string> Exact letters as on wire (I = invite exception, not i). */
    public function getListModeLetters(): array
    {
        return ['b', 'e', 'I'];
    }

    /** @return list<string> */
    public function getChannelSettingModesUnsetWithoutParam(): array
    {
        return [];
    }

    /** @return list<string> */
    public function getChannelSettingModesUnsetWithParam(): array
    {
        return [];
    }

    /** @return list<string> */
    public function getChannelSettingModesWithParamOnSet(): array
    {
        return [];
    }

    public function hasChannelRegisteredMode(): bool
    {
        return false;
    }

    public function hasPermanentChannelMode(): bool
    {
        return false;
    }
}
