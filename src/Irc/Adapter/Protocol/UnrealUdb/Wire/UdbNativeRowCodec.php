<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use InvalidArgumentException;

use function array_map;
use function chr;
use function count;
use function explode;
use function hash;
use function intdiv;
use function ord;
use function pack;
use function strlen;
use function strtolower;

/** Canonical UDB 4 component identity, row encoding and key hashing. */
final class UdbNativeRowCodec
{
    /**
     * @return list<string> decoded canonical bytes
     */
    public static function components(UdbBlock $block, string $path): array
    {
        if (!UdbPathCodec::isCanonicalPath($path)) {
            throw new InvalidArgumentException('A UDB logical row has a non-canonical path.');
        }
        $components = array_map(static fn (string $component): string => UdbPathCodec::decodeComponent($component) ?? throw new InvalidArgumentException('A UDB logical path component is invalid.'), explode('::', $path));
        $keyException = UdbBlock::Lines === $block && isset($components[1]) && 'f' === strtolower($components[0]);
        foreach ($components as $index => $component) {
            if ($keyException && 1 === $index) {
                continue;
            }
            $components[$index] = strtolower($component);
        }

        return $components;
    }

    /**
     * @param list<string> $parts decoded canonical components
     */
    public static function encode(UdbBlock $block, array $parts, UdbValue $value): string
    {
        $data = match ($value->kind) {
            UdbValueKind::None => '',
            UdbValueKind::String => $value->value,
            UdbValueKind::Number => self::decimalToLittleEndian($value->value),
        };
        $body = '';
        foreach ($parts as $part) {
            $body .= pack('V', strlen($part)) . $part;
        }
        $body .= $data;
        $length = 16 + strlen($body);

        return $block->letter() . chr(count($parts) & 255) . chr($value->kind->value) . "\0" . pack('V2', $length, strlen($data)) . "\0\0\0\0" . $body;
    }

    /**
     * @param list<string> $components
     */
    public static function keyHash(string $domain, UdbBlock $block, array $components): string
    {
        $key = $domain . "\0" . $block->letter() . pack('V', count($components));
        foreach ($components as $component) {
            $key .= pack('V', strlen($component)) . $component;
        }

        return hash('sha256', $key, true);
    }

    /**
     * @param list<string> $components
     */
    public static function sortKey(array $components): string
    {
        $key = '';
        foreach ($components as $component) {
            for ($i = 0, $length = strlen($component); $i < $length; ++$i) {
                $byte = ord($component[$i]);
                $key .= chr(1 + ($byte >> 4)) . chr(1 + ($byte & 15));
            }
            $key .= "\0";
        }

        return $key;
    }

    public static function decimalToLittleEndian(string $value): string
    {
        $decimal = (string) (UdbUnsignedDecimal::parse($value) ?? throw new InvalidArgumentException('UDB numeric value is not uint64.'));

        return self::encodeUnsignedWidth($decimal, 8);
    }

    private static function encodeUnsignedWidth(string $decimal, int $width): string
    {
        $bytes = '';
        for ($i = 0; $i < $width; ++$i) {
            [$decimal, $remainder] = self::divideDecimal($decimal, 256);
            $bytes .= chr($remainder & 255);
        }
        if ('0' !== $decimal) {
            throw new InvalidArgumentException('UDB numeric value exceeds uint64.');
        }

        return $bytes;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function divideDecimal(string $decimal, int $divisor): array
    {
        $quotient = '';
        $remainder = 0;
        for ($i = 0, $length = strlen($decimal); $i < $length; ++$i) {
            $value = $remainder * 10 + (int) $decimal[$i];
            $digit = intdiv($value, $divisor);
            $remainder = $value % $divisor;
            if ('' !== $quotient || 0 !== $digit) {
                $quotient .= (string) $digit;
            }
        }

        return ['' === $quotient ? '0' : $quotient, $remainder];
    }
}
