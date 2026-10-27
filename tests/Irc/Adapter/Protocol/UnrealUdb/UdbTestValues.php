<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbCapturedSnapshot;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use RuntimeException;

use function bin2hex;
use function chmod;
use function count;
use function fclose;
use function fopen;
use function mkdir;
use function random_bytes;
use function sys_get_temp_dir;

/** Schema-aware typed fixtures shared by the UDB adapter tests. */
final class UdbTestValues
{
    public static function fromLogical(UdbBlock $block, string $path, string $value): UdbValue
    {
        return UdbSchema::typedValueForStoredRecord($block->letter(), $path, $value)
            ?? throw new RuntimeException('Test fixture does not satisfy the canonical UDB value schema.');
    }

    /**
     * @param array<string, string|UdbValue> $records
     *
     * @return array<string, UdbValue>
     */
    public static function typedMap(UdbBlock $block, array $records): array
    {
        $typed = [];
        foreach ($records as $path => $value) {
            $typed[$path] = $value instanceof UdbValue ? $value : self::fromLogical($block, $path, $value);
        }

        return $typed;
    }

    /**
     * @param array<string, string|UdbValue> $records
     */
    public static function digest(UdbBlock $block, array $records): string
    {
        return UdbChecksum::fromMap($block, self::typedMap($block, $records));
    }

    public static function emptyDigest(UdbBlock $block): string
    {
        return UdbChecksum::empty($block);
    }

    /**
     * @param array<string, UdbValue> $records
     *
     * @return array<string, string>
     */
    public static function logicalMap(array $records): array
    {
        $logical = [];
        foreach ($records as $path => $value) {
            $logical[$path] = $value->logicalValue();
        }

        return $logical;
    }

    public static function mutation(string $blockLetter, string $path, ?string $logicalValue): UdbMutation
    {
        $block = UdbBlock::fromLetter($blockLetter);
        $value = null === $logicalValue ? null : UdbSchema::typedValueForStoredRecord($blockLetter, $path, $logicalValue);

        return new UdbMutation(
            $blockLetter,
            $path,
            null === $logicalValue ? null : ($value ?? UdbValue::string($logicalValue)),
        );
    }

    /**
     * @param array<string, array<string, string|UdbValue>> $blocks
     */
    public static function snapshot(array $blocks = [], int $watermark = 0): UdbCapturedSnapshot
    {
        $directory = sys_get_temp_dir() . '/udb-test-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0o700)) {
            throw new RuntimeException('Unable to create temporary UDB snapshot fixture.');
        }
        $files = [];
        $digests = [];
        $counts = [];
        foreach (UdbBlock::all() as $block) {
            $file = $directory . '/' . $block->letter() . '.rows';
            $stream = fopen($file, 'x');
            if (false === $stream) {
                throw new RuntimeException('Unable to create temporary UDB snapshot block fixture.');
            }
            chmod($file, 0o600);
            $rows = self::typedMap($block, $blocks[$block->letter()] ?? []);
            foreach ($rows as $path => $value) {
                UdbCapturedSnapshot::appendRow($stream, $path, $value);
            }
            fclose($stream);
            chmod($file, 0o400);
            $files[$block->letter()] = $file;
            $digests[$block->letter()] = UdbChecksum::fromMap($block, $rows);
            $counts[$block->letter()] = count($rows);
        }

        return new UdbCapturedSnapshot(
            $directory,
            $files,
            $digests,
            $counts,
            new UdbDatasetCheckpoint(
                new UdbStreamOrigin('002', '0123456789abcdef'),
                UdbUnsignedDecimal::fromInt($watermark),
            ),
        );
    }
}
