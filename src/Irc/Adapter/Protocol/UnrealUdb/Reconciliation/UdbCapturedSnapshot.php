<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use RuntimeException;

use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function pack;
use function rmdir;
use function strlen;
use function substr;
use function unlink;
use function unpack;

/** A private disk-backed immutable view captured from one database snapshot. */
final class UdbCapturedSnapshot
{
    /**
     * @param array<string, string> $files
     * @param array<string, string> $digests
     * @param array<string, int>    $counts
     */
    public function __construct(
        private readonly string $directory,
        private readonly array $files,
        private readonly array $digests,
        private readonly array $counts,
        public readonly UdbDatasetCheckpoint $checkpoint,
    ) {}

    public function __destruct()
    {
        $this->close();
    }

    public function digest(UdbBlock $block): string
    {
        return $this->digests[$block->letter()];
    }

    public function count(UdbBlock $block): int
    {
        return $this->counts[$block->letter()];
    }

    /**
     * @return iterable<array{0: string, 1: UdbValue}>
     */
    public function records(UdbBlock $block): iterable
    {
        $file = $this->files[$block->letter()] ?? throw new RuntimeException('The UDB snapshot block is unavailable.');
        yield from self::readFile($file);
    }

    /**
     * @return iterable<array{0: string, 1: UdbValue}>
     */
    public static function readFile(string $file): iterable
    {
        $stream = fopen($file, 'r');
        if (false === $stream) {
            throw new RuntimeException('The UDB snapshot spool could not be opened.');
        }

        try {
            while (!feof($stream)) {
                $header = self::readExact($stream, 9);
                if ('' === $header) {
                    break;
                }
                $lengths = self::decodeHeader($header);
                $path = self::readExact($stream, $lengths['path']);
                $raw = self::readExact($stream, $lengths['value']);
                $kind = UdbValueKind::tryFrom($lengths['kind']);
                if (null === $kind) {
                    throw new RuntimeException('The UDB snapshot spool has an unknown value kind.');
                }
                $value = match ($kind) {
                    UdbValueKind::None => UdbValue::none(),
                    UdbValueKind::String => UdbValue::string($raw),
                    UdbValueKind::Number => UdbValue::number(UdbUnsignedDecimal::parse(substr($raw, 1)) ?? throw new RuntimeException('The UDB snapshot spool has an invalid number.')),
                };
                yield [$path, $value];
            }
        } finally {
            fclose($stream);
        }
    }

    public function close(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    /** @param resource $stream */
    public static function appendRow($stream, string $path, UdbValue $value): void
    {
        $logical = $value->logicalValue();
        $record = pack('N', strlen($path)) . pack('C', $value->kind->value) . pack('N', strlen($logical)) . $path . $logical;
        $offset = 0;
        $length = strlen($record);
        while ($offset < $length) {
            $written = fwrite($stream, substr($record, $offset));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('The UDB snapshot spool could not be written.');
            }
            $offset += $written;
        }
    }

    /** @return array{path: int, kind: int, value: int} */
    private static function decodeHeader(string $header): array
    {
        $lengths = 9 === strlen($header) ? unpack('Npath/Ckind/Nvalue', $header) : false;
        if (!is_array($lengths) || !is_int($lengths['path'] ?? null) || !is_int($lengths['kind'] ?? null) || !is_int($lengths['value'] ?? null)) {
            throw new RuntimeException('The UDB snapshot spool is malformed.');
        }

        return ['path' => $lengths['path'], 'kind' => $lengths['kind'], 'value' => $lengths['value']];
    }

    /** @param resource $stream */
    private static function readExact($stream, int $length): string
    {
        $value = '';
        while (0 < ($remaining = $length - strlen($value))) {
            $chunk = fread($stream, $remaining);
            if (false === $chunk || '' === $chunk) {
                if ('' === $value) {
                    return '';
                }
                throw new RuntimeException('The UDB snapshot spool ended unexpectedly.');
            }
            $value .= $chunk;
        }

        return $value;
    }
}
