<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Application\Port\In\ChannelModeSupportInterface;

use function array_merge;
use function implode;
use function in_array;
use function str_split;

/**
 * Formats channel modes and parameter values for UDB Block C record (C::<#channel>::modes).
 *
 * Requirements per UDB v4 specification:
 * - Excludes +r (registered channel mode, managed by UDB Block C profile).
 * - Excludes +P (permanent channel mode, managed by C::<#channel>::persistent).
 * - Excludes prefix modes (q, a, o, h, v) and list modes (b, e, I).
 * - Format: <modestring_with_leading_plus> <param1> <param2> ...
 * - Parameters must strictly follow the exact order of mode letters that take a param on set.
 * - Returns null when no modes are present.
 */
final readonly class UdbChannelModesFormatter
{
    /**
     * @param array<string, string> $modeParams Mode letter => param value
     */
    public function format(string $modeStr, array $modeParams, ChannelModeSupportInterface $support): ?string
    {
        if ('' === $modeStr) {
            return null;
        }

        $excludedLetters = array_merge(
            $support->getSupportedPrefixModes(),
            $support->getListModeLetters(),
        );

        $registeredLetter = $support->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter) {
            $excludedLetters[] = $registeredLetter;
        }

        $permanentLetter = $support->getPermanentChannelModeLetter();
        if (null !== $permanentLetter) {
            $excludedLetters[] = $permanentLetter;
        }

        $withParamOnSet = $support->getChannelSettingModesWithParamOnSet();

        $cleanLetters = [];
        foreach (str_split($modeStr) as $char) {
            if ('+' === $char || '-' === $char) {
                continue;
            }
            if (in_array($char, $excludedLetters, true)) {
                continue;
            }
            if (!in_array($char, $cleanLetters, true)) {
                $cleanLetters[] = $char;
            }
        }

        if ([] === $cleanLetters) {
            return null;
        }

        $orderedParams = [];
        foreach ($cleanLetters as $letter) {
            if (in_array($letter, $withParamOnSet, true)) {
                $paramValue = $modeParams[$letter] ?? null;
                if (null !== $paramValue && '' !== $paramValue) {
                    $orderedParams[] = $paramValue;
                }
            }
        }

        $formatted = '+' . implode('', $cleanLetters);
        if ([] !== $orderedParams) {
            $formatted .= ' ' . implode(' ', $orderedParams);
        }

        return $formatted;
    }
}
