<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

final class UdbAutomaticModeProfile
{
    private const array MODES = [
        'wallops' => 'w', 'bot' => 'B', 'no-ctcp' => 'T', 'censor' => 'G',
        'hide-channels' => 'p', 'registered-only' => 'R', 'tls-only' => 'Z',
        'private-deaf' => 'D', 'channel-deaf' => 'd',
    ];

    /**
     * Unknown/offline inventories preserve the native protocol projection.
     *
     * @param list<string>               $intentions
     * @param array<string, string>|null $supported
     */
    public static function encode(array $intentions, ?array $supported = null): ?string
    {
        $letters = '';
        foreach ($intentions as $intention) {
            if (null === $supported || isset($supported[$intention])) {
                $letters .= self::MODES[$intention] ?? '';
            }
        }

        return '' === $letters ? null : '+' . $letters;
    }
}
