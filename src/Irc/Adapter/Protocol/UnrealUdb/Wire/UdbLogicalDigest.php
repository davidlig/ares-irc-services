<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use InvalidArgumentException;
use RuntimeException;

use function array_key_exists;
use function array_slice;
use function bin2hex;
use function chr;
use function count;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function glob;
use function hash;
use function intdiv;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function mkdir;
use function ord;
use function pack;
use function random_bytes;
use function rmdir;
use function sprintf;
use function strcmp;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function unpack;
use function usort;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;

/** Canonical UDB 4 logical-row digest, independent of physical storage layout. */
final class UdbLogicalDigest
{
    private const int PARTITIONS = 4096;

    private const int LEAF_WEIGHT_LIMIT = 16777216;

    /**
     * @param iterable<array{0: string, 1: mixed}> $records encoded path and explicit typed value
     */
    public static function fromRecords(UdbBlock $block, iterable $records): string
    {
        return self::summarize($block, $records)['digest'];
    }

    /**
     * @param iterable<array-key, mixed> $records canonical path => typed value
     */
    public static function fromMap(UdbBlock $block, iterable $records): string
    {
        return self::fromRecords($block, self::mapRows($records));
    }

    /** @param iterable<array{0: string, 1: mixed}> $records
     * @return array{digest: string, count: int, weight: int}
     */
    public static function summarize(UdbBlock $block, iterable $records): array
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'udb4-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0o700)) {
            throw new RuntimeException('Unable to create private UDB digest spool.');
        }

        /** @var array<int, string> $partitions */
        $partitions = [];
        try {
            foreach ($records as [$path, $value]) {
                if (!$value instanceof UdbValue) {
                    throw new InvalidArgumentException('UDB logical rows require explicit typed values.');
                }
                $parts = UdbNativeRowCodec::components($block, $path);
                $row = UdbNativeRowCodec::encode($block, $parts, $value);
                $entityDepth = UdbBlock::Lines === $block ? 2 : 1;
                $entity = UdbNativeRowCodec::keyHash('UDB4/entity', $block, array_slice($parts, 0, $entityDepth));
                $partition = (ord($entity[0]) << 4) | (ord($entity[1]) >> 4);
                $keyHash = UdbNativeRowCodec::keyHash('UDB4/key', $block, $parts);
                $sortKey = UdbNativeRowCodec::sortKey($parts);
                $frame = pack('N', strlen($sortKey)) . pack('N', strlen($row)) . $keyHash . $sortKey . $row;
                if (!array_key_exists($partition, $partitions)) {
                    $partitions[$partition] = $directory . DIRECTORY_SEPARATOR . sprintf('p%04x', $partition);
                }
                self::append($partitions[$partition], $frame);
            }

            $summaries = '';
            $totalCount = 0;
            $totalWeight = 0;
            for ($partition = 0; $partition < self::PARTITIONS; ++$partition) {
                $file = $partitions[$partition] ?? null;
                $summary = self::leaf($block, $partition, $file, 0, str_repeat("\0", 32), $directory);
                $totalCount = self::checkedAdd($totalCount, $summary['count']);
                $totalWeight = self::checkedAdd($totalWeight, $summary['weight']);
                $summaries .= pack('v', $partition) . self::packUint64($summary['count']) . self::packUint64($summary['weight']) . $summary['digest'];
            }

            return [
                'digest' => hash('sha256', "UDB4/block\0" . $block->letter() . $summaries . self::packUint64($totalCount) . self::packUint64($totalWeight)),
                'count' => $totalCount,
                'weight' => $totalWeight,
            ];
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @return array{digest: string, count: int, weight: int}
     */
    private static function leaf(UdbBlock $block, int $partition, ?string $file, int $depth, string $prefix, string $directory): array
    {
        [$count, $weight] = self::measure($file);
        $position = $block->letter() . pack('v2', $partition, $depth) . $prefix;
        $totals = self::packUint64($count) . self::packUint64($weight);
        if ($weight <= self::LEAF_WEIGHT_LIMIT) {
            $rows = self::readRows($file);
            usort($rows, static fn (array $left, array $right): int => strcmp($left['sort'], $right['sort']));
            $content = '';
            $last = null;
            foreach ($rows as $row) {
                if (null !== $last && $last === $row['sort']) {
                    throw new InvalidArgumentException('UDB logical rows contain a canonical key collision.');
                }
                $last = $row['sort'];
                $content .= pack('V', strlen($row['row'])) . $row['row'];
            }

            return ['digest' => hash('sha256', "UDB4/leaf\0" . $position . $content . $totals, true), 'count' => $count, 'weight' => $weight];
        }
        if (256 === $depth) {
            throw new InvalidArgumentException('UDB logical key hashes collide beyond the radix depth.');
        }

        $leftFile = $directory . DIRECTORY_SEPARATOR . sprintf('l%04x-%03d-%s', $partition, $depth, bin2hex($prefix));
        $rightFile = $directory . DIRECTORY_SEPARATOR . sprintf('r%04x-%03d-%s', $partition, $depth, bin2hex($prefix));
        self::split($file, $depth, $leftFile, $rightFile);
        $left = self::leaf($block, $partition, self::existing($leftFile), $depth + 1, $prefix, $directory);
        $bit = 1 << (7 - ($depth % 8));
        $prefixByte = intdiv($depth, 8);
        $rightPrefix = $prefix;
        $rightPrefix[$prefixByte] = chr((ord($rightPrefix[$prefixByte]) | $bit) & 255);
        $right = self::leaf($block, $partition, self::existing($rightFile), $depth + 1, $rightPrefix, $directory);
        foreach ([$leftFile, $rightFile] as $temporary) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return [
            'digest' => hash('sha256', "UDB4/branch\0" . $position . $left['digest'] . self::packUint64($left['count']) . self::packUint64($left['weight']) . $right['digest'] . self::packUint64($right['count']) . self::packUint64($right['weight']) . $totals, true),
            'count' => $count,
            'weight' => $weight,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function measure(?string $file): array
    {
        if (null === $file || !is_file($file)) {
            return [0, 0];
        }
        $handle = fopen($file, 'r');
        if (false === $handle) {
            throw new RuntimeException('Unable to read UDB digest spool.');
        }
        $count = 0;
        $weight = 0;
        try {
            while (!feof($handle)) {
                $row = self::readFrame($handle);
                if (null === $row) {
                    break;
                }
                $count = self::checkedAdd($count, 1);
                $weight = self::checkedAdd($weight, strlen($row['row']) + 128);
            }
        } finally {
            fclose($handle);
        }

        return [$count, $weight];
    }

    /**
     * @return list<array{sort: string, hash: string, row: string}>
     */
    private static function readRows(?string $file): array
    {
        if (null === $file || !is_file($file)) {
            return [];
        }
        $handle = fopen($file, 'r');
        if (false === $handle) {
            throw new RuntimeException('Unable to read UDB digest spool.');
        }
        $rows = [];
        try {
            while (!feof($handle)) {
                $row = self::readFrame($handle);
                if (null === $row) {
                    break;
                }
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * @return array{sort: string, hash: string, row: string}|null
     */
    /**
     * @param resource $handle
     *
     * @return array{sort: string, hash: string, row: string}|null
     */
    private static function readFrame($handle): ?array
    {
        $header = self::readExact($handle, 40);
        if ('' === $header) {
            return null;
        }
        $lengths = 40 === strlen($header) ? unpack('Nsort/Nrow', substr($header, 0, 8)) : false;
        if (false === $lengths || !is_int($lengths['sort'] ?? null) || !is_int($lengths['row'] ?? null)) {
            throw new RuntimeException(40 !== strlen($header) ? 'Corrupt UDB digest spool header.' : 'Corrupt UDB digest spool lengths.');
        }
        $sortLength = $lengths['sort'];
        $rowLength = $lengths['row'];
        $sort = self::readExact($handle, $sortLength);
        $row = self::readExact($handle, $rowLength);
        if (strlen($sort) !== $sortLength || strlen($row) !== $rowLength) {
            throw new RuntimeException('Corrupt UDB digest spool row.');
        }

        return ['hash' => substr($header, 8, 32), 'sort' => $sort, 'row' => $row];
    }

    /** @param resource $handle */
    private static function readExact($handle, int $length): string
    {
        $value = '';
        while (0 < ($remaining = $length - strlen($value)) && !feof($handle)) {
            $chunk = fread($handle, $remaining);
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $value .= $chunk;
        }

        return $value;
    }

    private static function split(?string $source, int $depth, string $left, string $right): void
    {
        if (null === $source || !is_file($source)) {
            return;
        }
        $input = fopen($source, 'r');
        $leftHandle = fopen($left, 'w');
        $rightHandle = fopen($right, 'w');
        if (false === $input || false === $leftHandle || false === $rightHandle) {
            throw new RuntimeException('Unable to split UDB digest spool.');
        }
        try {
            while (!feof($input)) {
                $row = self::readFrame($input);
                if (null === $row) {
                    break;
                }
                $frame = pack('N', strlen($row['sort'])) . pack('N', strlen($row['row'])) . $row['hash'] . $row['sort'] . $row['row'];
                $set = 0 !== (ord($row['hash'][intdiv($depth, 8)]) & (1 << (7 - ($depth % 8))));
                self::writeAll($set ? $rightHandle : $leftHandle, $frame);
            }
        } finally {
            fclose($input);
            fclose($leftHandle);
            fclose($rightHandle);
        }
    }

    private static function append(string $path, string $value): void
    {
        $handle = fopen($path, 'a');
        if (false === $handle) {
            throw new RuntimeException('Unable to append UDB digest spool.');
        }
        try {
            self::writeAll($handle, $value);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $value): void
    {
        $offset = 0;
        while ($offset < strlen($value)) {
            $written = fwrite($handle, substr($value, $offset));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('Unable to write UDB digest spool.');
            }
            $offset += $written;
        }
    }

    private static function existing(string $path): ?string
    {
        return is_file($path) ? $path : null;
    }

    private static function packUint64(int $value): string
    {
        if (0 > $value) {
            throw new InvalidArgumentException('UDB checksum totals cannot be negative.');
        }

        // Aggregate totals are bounded PHP integers, unlike row uint64 values.
        // Explicit low/high little-endian words avoid decimal division per leaf.
        return pack('V2', $value & 0xFFFFFFFF, $value >> 32);
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if (0 > $right || $left > PHP_INT_MAX - $right) {
            throw new RuntimeException('UDB checksum aggregate exceeds the platform integer range.');
        }

        return $left + $right;
    }

    /** @param iterable<array-key, mixed> $records
     * @return iterable<array{0: string, 1: UdbValue}>
     */
    private static function mapRows(iterable $records): iterable
    {
        foreach ($records as $path => $value) {
            if (!is_string($path) || !$value instanceof UdbValue) {
                throw new InvalidArgumentException('UDB logical maps require canonical paths and typed values.');
            }
            yield [$path, $value];
        }
    }
}
