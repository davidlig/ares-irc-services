<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use function in_array;
use function is_string;
use function preg_split;
use function strtoupper;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/** Produces a bounded command identifier without retaining IRC arguments. */
final class OperServCommandLogSanitizer
{
    private const array COMMANDS = ['GLINE', 'GLOBAL', 'HELP', 'IRCOP', 'KILL', 'MOTD', 'RAW', 'ROLE'];

    private function __construct() {}

    public static function commandName(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 2, PREG_SPLIT_NO_EMPTY) ?: [];
        $command = strtoupper(is_string($parts[0] ?? null) ? $parts[0] : '');

        return in_array($command, self::COMMANDS, true) ? $command : 'UNKNOWN';
    }
}
