<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use function array_map;
use function chr;
use function count;
use function explode;
use function hexdec;
use function implode;
use function in_array;
use function ord;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_pad;
use function strlen;
use function strpbrk;
use function strtoupper;
use function substr;

use const PHP_INT_MAX;
use const STR_PAD_LEFT;

/**
 * Canonical UDB 4 path-component codec.
 *
 * Path components are joined with "::" and percent-encoded so that ":",
 * "%", control bytes and non-ASCII bytes never appear literally. Decoding is
 * strict: only canonical encodings (bytes the encoder would have escaped)
 * are accepted, and embedded NUL bytes are rejected.
 *
 * Limits mirror udb_internal.h in the UnrealIRCd UDB module:
 *   UDB_RECORD_PATH_MAX 8192, UDB_PATH_MAX_COMPONENTS 8,
 *   UDB_COMPONENT_RAW_MAX 4608, UDB_COMPONENT_ENCODED_MAX 13824,
 *   UDB_RECORD_VALUE_MAX 4096, UDB_RECORD_LINE_MAX PATH+VALUE+32,
 *   UDB_TXID_MAX 31.
 */
final class UdbPathCodec
{
    public const int PATH_MAX = 8192;

    public const int COMPONENT_RAW_MAX = 4608;

    public const int COMPONENT_ENCODED_MAX = 13824;

    public const int PATH_COMPONENTS_MAX = 8;

    public const int VALUE_MAX = 4096;

    public const int RECORD_LINE_MAX = self::PATH_MAX + self::VALUE_MAX + 32;

    public const int IRC_LINE_MAX = 16384;

    public const int S2S_OVERHEAD_MAX = 256;

    public const int TXID_MAX = 31;

    /** Canonical percent-encoding of one path component. Returns null when the component cannot be represented. */
    public static function encodeComponent(string $raw): ?string
    {
        return self::encodeWithinLimits($raw, self::COMPONENT_RAW_MAX, self::COMPONENT_ENCODED_MAX);
    }

    /** Strict canonical decode. Returns null for malformed, non-canonical or NUL-containing input. */
    public static function decodeComponent(string $encoded): ?string
    {
        $decoded = '';
        $length = strlen($encoded);
        for ($i = 0; $i < $length; ++$i) {
            $byte = $encoded[$i];
            if ('%' !== $byte) {
                if (strlen($decoded) + 1 > self::COMPONENT_RAW_MAX) {
                    return null;
                }
                $decoded .= $byte;

                continue;
            }

            $hex = substr($encoded, $i + 1, 2);
            if (2 !== strlen($hex) || 1 !== preg_match('/^[0-9A-Fa-f]{2}$/', $hex)) {
                return null;
            }

            $value = (int) hexdec($hex);
            if (0 === $value) {
                return null;
            }

            // Canonicality: only bytes the encoder escapes may appear escaped.
            if (58 !== $value && 37 !== $value && $value > 32 && $value < 127) {
                return null;
            }

            if (strlen($decoded) + 1 > self::COMPONENT_RAW_MAX) {
                return null;
            }
            $decoded .= chr((int) $value & 0xFF);
            $i += 2;
        }

        return $decoded;
    }

    /**
     * Encodes a raw component list into a wire path joined with "::".
     *
     * @param list<string> $components
     */
    public static function encodePath(array $components): ?string
    {
        if ([] === $components || count($components) > self::PATH_COMPONENTS_MAX) {
            return null;
        }

        $encoded = array_map(static fn (string $component): ?string => self::encodeComponent($component), $components);
        if (in_array(null, $encoded, true)) {
            return null;
        }

        $path = implode('::', $encoded);
        if (strlen($path) > self::PATH_MAX || !self::isCanonicalPath($path)) {
            return null;
        }

        return $path;
    }

    /**
     * UDB record limit check for a full (already encoded) path and optional value.
     * Mirrors udb_record_fits_limits(): component sizes, decoded sizes, value
     * size, CRLF rejection and the serialized "path value\n" line budget.
     */
    public static function fitsLimits(string $path, ?string $value): bool
    {
        return null !== self::decodePathWithinLimits($path, $value);
    }

    /** Validates a canonical full path (every component decodes and re-encodes identically). */
    public static function isCanonicalPath(string $path): bool
    {
        return null !== self::decodeCanonicalPath($path);
    }

    /**
     * Validates an encoded path and record value, returning the decoded components on success.
     *
     * @return list<string>|null
     */
    public static function decodePathWithinLimits(string $path, ?string $value): ?array
    {
        $components = self::decodeCanonicalPath($path);
        if (null === $components) {
            return null;
        }

        if (null !== $value && (strlen($value) > self::VALUE_MAX || strpbrk($value, "\r\n"))) {
            return null;
        }

        $valueLength = null !== $value && '' !== $value ? 1 + strlen($value) : 0;
        $lineLength = strlen($path) + $valueLength + 1;

        return $lineLength <= self::RECORD_LINE_MAX ? $components : null;
    }

    /** Checks a complete UDB record line, including conservative framing and CRLF budget. */
    public static function fitsWireLine(string $prefix, string $target, string $command, string $path, string $value): bool
    {
        $line = sprintf(':%s DB %s %s %s :%s', $prefix, $target, $command, $path, $value);

        return strlen($line) + 2 + self::S2S_OVERHEAD_MAX <= self::IRC_LINE_MAX;
    }

    /** Strict txid validation (max 31 chars of [A-Za-z0-9_-]). */
    public static function isValidTxid(string $txid): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]{1,' . self::TXID_MAX . '}$/', $txid);
    }

    /** Parses an 1-8 hex digit checksum into the canonical 8-digit uppercase form. */
    public static function normalizeChecksum(string $checksum): ?string
    {
        if (1 !== preg_match('/^[0-9A-Fa-f]{1,8}$/', $checksum)) {
            return null;
        }

        return strtoupper(str_pad($checksum, 8, '0', STR_PAD_LEFT));
    }

    /** Parses a strict unsigned-long decimal (C udb_strtoul_strict equivalent). */
    public static function parseUnsigned(string $value): ?UdbUnsignedDecimal
    {
        return UdbUnsignedDecimal::parse($value);
    }

    /** Parses a bounded unsigned value that is intentionally represented as a PHP int. */
    public static function parseUnsignedInt(string $value, int $maximum): ?int
    {
        $unsigned = UdbUnsignedDecimal::parse($value);
        if (null === $unsigned) {
            return null;
        }

        $number = $unsigned->toInt();
        if (null === $number || $number > $maximum) {
            return null;
        }

        return $number;
    }

    /** Parses a durable or wire timestamp with the bounded time_t semantics of udb_parse_time_t(). */
    public static function parseTimeT(string $value): ?int
    {
        return self::parseUnsignedInt($value, PHP_INT_MAX);
    }

    /** @return list<string>|null */
    private static function decodeCanonicalPath(string $path): ?array
    {
        if ('' === $path || strlen($path) > self::PATH_MAX) {
            return null;
        }

        $components = explode('::', $path);
        if (count($components) > self::PATH_COMPONENTS_MAX) {
            return null;
        }

        $decodedComponents = [];
        foreach ($components as $component) {
            if ('' === $component || strlen($component) > self::COMPONENT_ENCODED_MAX) {
                return null;
            }

            $decoded = self::decodeComponent($component);
            if (null === $decoded || strlen($decoded) > self::COMPONENT_RAW_MAX) {
                return null;
            }

            if (self::encodeComponent($decoded) !== $component) {
                return null;
            }
            $decodedComponents[] = $decoded;
        }

        return $decodedComponents;
    }

    private static function encodeWithinLimits(string $raw, int $rawLimit, int $encodedLimit): ?string
    {
        $encoded = '';
        $length = strlen($raw);
        if ($length > $rawLimit || str_contains($raw, "\0")) {
            return null;
        }
        for ($i = 0; $i < $length; ++$i) {
            $byte = $raw[$i];
            $ord = ord($byte);
            $fragment = 58 === $ord || 37 === $ord || $ord <= 32 || $ord >= 127
                ? sprintf('%%%02X', $ord) : $byte;
            $next = self::appendEncoded($encoded, $fragment, $encodedLimit);
            if (null === $next) {
                return null;
            }
            $encoded = $next;
        }

        return $encoded;
    }

    private static function appendEncoded(string $encoded, string $fragment, int $limit): ?string
    {
        return strlen($encoded) + strlen($fragment) <= $limit ? $encoded . $fragment : null;
    }
}
