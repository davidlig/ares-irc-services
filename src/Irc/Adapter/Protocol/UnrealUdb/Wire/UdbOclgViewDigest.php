<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use function count;
use function hash;
use function ksort;
use function pack;
use function strlen;

use const SORT_STRING;

/** Computes the UDB 4 OCLG full-view digest. */
final class UdbOclgViewDigest
{
    /**
     * @param array<string, string> $entries operclass name => lowercase SHA-256 effective digest
     */
    public static function fromEntries(bool $ready, array $entries): string
    {
        ksort($entries, SORT_STRING);
        $payload = "UDB-OCLG-VIEW-v1\0" . pack('N2', $ready ? 1 : 0, count($entries));
        foreach ($entries as $name => $digest) {
            $payload .= pack('N', strlen($name)) . $name . $digest . "\0";
        }

        return hash('sha256', $payload);
    }

    public static function isValid(string $digest): bool
    {
        return 1 === preg_match('/^[0-9a-f]{64}$/D', $digest);
    }
}
