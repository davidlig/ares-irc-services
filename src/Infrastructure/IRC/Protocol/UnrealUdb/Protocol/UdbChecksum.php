<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol;

use function hash;
use function preg_match;
use function sprintf;
use function str_pad;
use function strcmp;
use function strlen;
use function strtoupper;
use function usort;

use const STR_PAD_LEFT;

/**
 * UDB 4 block checksum (digest).
 *
 * Identical to udb_compute_tree_checksum(): CRC-32 (IEEE, reflected,
 * init/xorout 0xFFFFFFFF) over the lexically sorted "path value\n" lines of
 * the block's logical records, rendered as 8 uppercase hex digits. An empty
 * block hashes to 00000000.
 */
final class UdbChecksum
{
    public const string EMPTY = '00000000';

    /**
     * @param iterable<array{0: string, 1: string}> $records Tuples of [encodedPath, value]
     */
    public static function fromRecords(iterable $records): string
    {
        $lines = [];
        foreach ($records as [$path, $value]) {
            $lines[] = sprintf('%s %s', $path, $value);
        }

        return self::fromLines($lines);
    }

    /**
     * @param list<string> $lines Pre-rendered "path value" lines (without newline)
     */
    public static function fromLines(array $lines): string
    {
        if ([] === $lines) {
            return self::EMPTY;
        }

        usort($lines, strcmp(...));

        $payload = '';
        foreach ($lines as $line) {
            $payload .= $line . "\n";
        }

        return strtoupper(hash('crc32b', $payload));
    }

    /** Normalizes a wire checksum to the 8-digit uppercase comparison form, or null when invalid. */
    public static function parse(string $checksum): ?string
    {
        if (strlen($checksum) > 8 || 1 !== preg_match('/^[0-9A-Fa-f]{1,8}$/', $checksum)) {
            return null;
        }

        return strtoupper(str_pad($checksum, 8, '0', STR_PAD_LEFT));
    }
}
