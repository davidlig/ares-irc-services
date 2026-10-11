<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

use function bin2hex;
use function chmod;
use function fclose;
use function fopen;
use function glob;
use function is_dir;
use function is_file;
use function is_int;
use function is_string;
use function mkdir;
use function random_bytes;
use function rmdir;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;

/** Captures all six blocks from one SQL view, then releases SQL before wire I/O. */
final readonly class UdbSnapshotProvider implements UdbSnapshotProviderInterface
{
    public function __construct(private Connection $connection) {}

    public function capture(): UdbCapturedSnapshot
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ares-udb4-' . bin2hex(random_bytes(16));
        if (!mkdir($directory, 0o700)) {
            throw new RuntimeException('Unable to create private UDB snapshot spool.');
        }
        chmod($directory, 0o700);
        $files = [];
        $digests = [];
        $counts = [];
        try {
            $this->setConsistentIsolation();
            $this->connection->beginTransaction();
            $checkpointRow = $this->connection->fetchAssociative('SELECT dataset_sid, dataset_epoch, dataset_watermark FROM udb_authority_state WHERE id = 1');
            if (false === $checkpointRow || !is_string($checkpointRow['dataset_sid']) || !is_string($checkpointRow['dataset_epoch']) || !is_string($checkpointRow['dataset_watermark'])) {
                throw new UnexpectedValueException('The UDB dataset checkpoint has not been initialized.');
            }
            $watermark = UdbUnsignedDecimal::parse($checkpointRow['dataset_watermark']);
            if (null === $watermark) {
                throw new UnexpectedValueException('The persisted UDB dataset watermark is invalid.');
            }
            $checkpoint = new UdbDatasetCheckpoint(new UdbStreamOrigin($checkpointRow['dataset_sid'], $checkpointRow['dataset_epoch']), $watermark);

            foreach (UdbBlock::all() as $block) {
                $file = $directory . DIRECTORY_SEPARATOR . $block->letter() . '.rows';
                $stream = fopen($file, 'x');
                if (false === $stream) {
                    throw new RuntimeException('Unable to open private UDB snapshot block spool.');
                }
                chmod($file, 0o600);
                $files[$block->letter()] = $file;
                $counts[$block->letter()] = 0;
                $lastId = 0;
                try {
                    while (true) {
                        $rows = $this->connection->fetchAllAssociative(
                            'SELECT id, path, value, value_kind FROM udb_records WHERE block = ? AND id > ? ORDER BY id ASC LIMIT 256',
                            [$block->letter(), $lastId],
                        );
                        if ([] === $rows) {
                            break;
                        }
                        foreach ($rows as $row) {
                            $lastId = self::sqlInt($row['id']);
                            $path = $row['path'];
                            try {
                                $raw = Type::getType(Types::BINARY)->convertToPHPValue($row['value'], $this->connection->getDatabasePlatform());
                            } catch (Throwable $exception) {
                                throw new UnexpectedValueException('A persisted UDB row has invalid SQL fields.', previous: $exception);
                            }
                            if (!is_string($path) || !is_string($raw)) {
                                throw new UnexpectedValueException('A persisted UDB row has invalid SQL fields.');
                            }
                            $kind = UdbValueKind::tryFrom(self::sqlInt($row['value_kind']));
                            if (null === $kind) {
                                throw new UnexpectedValueException('A persisted UDB row has an unknown value kind.');
                            }
                            $value = match ($kind) {
                                UdbValueKind::None => UdbValue::none(),
                                UdbValueKind::String => UdbValue::string($raw),
                                UdbValueKind::Number => UdbValue::number(UdbUnsignedDecimal::parse(substr($raw, 1)) ?? throw new UnexpectedValueException('A persisted UDB number is invalid.')),
                            };
                            if ($value->logicalValue() !== $raw) {
                                throw new UnexpectedValueException('A persisted UDB row does not match its stored value kind.');
                            }
                            if (!UdbSchema::matchesStoredRecordType($block->letter(), $path, $value)) {
                                throw new UnexpectedValueException('A persisted UDB row does not match the canonical schema.');
                            }
                            UdbCapturedSnapshot::appendRow($stream, $path, $value);
                            ++$counts[$block->letter()];
                        }
                    }
                } finally {
                    fclose($stream);
                }
                chmod($file, 0o400);
            }

            $this->connection->commit();
            foreach (UdbBlock::all() as $block) {
                $digests[$block->letter()] = UdbChecksum::fromRecords($block, $this->readRows($files[$block->letter()]));
            }

            return new UdbCapturedSnapshot($directory, $files, $digests, $counts, $checkpoint);
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
            throw $exception;
        }
    }

    /**
     * @return iterable<array{0: string, 1: UdbValue}>
     */
    private function readRows(string $file): iterable
    {
        yield from UdbCapturedSnapshot::readFile($file);
    }

    private function setConsistentIsolation(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        }
    }

    private static function sqlInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            throw new UnexpectedValueException('A persisted UDB integer field is invalid.');
        }

        return (int) $value;
    }
}
