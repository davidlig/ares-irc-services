<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;

use function preg_match;
use function strlen;

/** Compatibility entry point for block-scoped native logical digests. */
final class UdbChecksum
{
    /** @var array<string, string> */
    private static array $emptyDigests = [];

    /**
     * @param iterable<array{0: string, 1: UdbValue}> $records
     */
    public static function fromRecords(UdbBlock $block, iterable $records): string
    {
        return UdbLogicalDigest::fromRecords($block, $records);
    }

    /**
     * @param iterable<string, UdbValue> $records canonical path => typed value
     */
    public static function fromMap(UdbBlock $block, iterable $records): string
    {
        return UdbLogicalDigest::fromMap($block, $records);
    }

    public static function empty(UdbBlock $block): string
    {
        return self::$emptyDigests[$block->letter()] ??= self::fromRecords($block, []);
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
