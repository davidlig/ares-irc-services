<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use function hash;
use function preg_match;
use function sprintf;
use function strcmp;
use function strlen;
use function usort;

/**
 * UDB 4 block checksum (digest).
 *
 * Identical to udb_compute_tree_digest(): lowercase SHA-256 over the bytewise
 * lexically sorted "path value\n" lines of the block's logical records.
 */
final class UdbChecksum
{
    public const string EMPTY = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

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
        usort($lines, strcmp(...));

        $payload = '';
        foreach ($lines as $line) {
            $payload .= $line . "\n";
        }

        return hash('sha256', $payload);
    }

    /** Accepts only the canonical 64-character lowercase wire digest. */
    public static function parse(string $checksum): ?string
    {
        if (64 !== strlen($checksum) || 1 !== preg_match('/^[0-9a-f]{64}$/D', $checksum)) {
            return null;
        }

        return $checksum;
    }
}
