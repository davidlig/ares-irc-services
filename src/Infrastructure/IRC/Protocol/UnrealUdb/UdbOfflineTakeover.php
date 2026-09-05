<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\Udb\Entity\UdbAuthorityState;
use App\Domain\Udb\Entity\UdbBlockState;
use App\Domain\Udb\Entity\UdbRecord;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbPathCodec;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbSchema;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

use function array_key_exists;
use function count;
use function explode;
use function fclose;
use function fgets;
use function flock;
use function fopen;
use function hash;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function ksort;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;

use const LOCK_EX;
use const LOCK_NB;
use const SORT_STRING;

/**
 * Imports a durable, offline UDB generation as the services authority.
 *
 * The `.udb.lock` filename is the lock contract for every process that
 * operates on an offline UDB directory, including the daemon deployment.
 */
final readonly class UdbOfflineTakeover implements UdbOfflineTakeoverInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private UdbRecordExporter $exporter,
    ) {}

    /** @return string SHA-256 fingerprint of the approved replacement set */
    public function takeover(string $directory, bool $apply = true): string
    {
        $directory = rtrim($directory, '/');
        if ('' === $directory || !is_dir($directory)) {
            throw new RuntimeException('The UDB takeover directory does not exist.');
        }

        $lock = fopen($directory . '/.udb.lock', 'c');
        // @codeCoverageIgnoreStart
        // A file may disappear or become inaccessible after is_dir() (TOCTOU).
        // This cannot be reproduced reliably without mutating the process filesystem permissions.
        if (false === $lock) {
            throw new RuntimeException('Unable to open the UDB takeover lock.');
        }
        // @codeCoverageIgnoreEnd

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('The UDB directory is locked by another UDB process. Stop the daemon before takeover.');
            }

            [$generation, $snapshots] = $this->loadSnapshots($directory);
            $candidate = [
                'N' => $this->exportRecords($this->exporter->allNickRecords(), UdbBlock::Nicks),
                'C' => $this->exportRecords($this->exporter->allChannelRecords(), UdbBlock::Channels),
                'I' => $snapshots['I'],
                // Do not rewrite settings: notably S::propagator is retained byte-for-byte.
                'S' => $snapshots['S'],
                'L' => $snapshots['L'],
                'K' => $this->exportRecords($this->exporter->allGlineRecords(), UdbBlock::Lines),
            ];
            $fingerprint = $this->fingerprint($candidate, $generation);

            if (!$apply) {
                return $fingerprint;
            }

            $this->em->wrapInTransaction(function () use ($candidate, $fingerprint): void {
                $this->em->createQuery('DELETE FROM App\Domain\Udb\Entity\UdbRecord r')->execute();
                $this->em->createQuery('DELETE FROM App\Domain\Udb\Entity\UdbBlockState s')->execute();

                foreach (UdbBlock::all() as $block) {
                    $records = $candidate[$block->letter()];
                    foreach ($records as $path => $value) {
                        $this->em->persist(new UdbRecord($block->letter(), $path, $value));
                    }
                    $this->em->persist(new UdbBlockState($block->letter(), $this->checksum($records)));
                }

                $authority = $this->em->find(UdbAuthorityState::class, 1);
                if (!$authority instanceof UdbAuthorityState) {
                    $authority = new UdbAuthorityState();
                    $this->em->persist($authority);
                }
                $authority->approve($fingerprint);
                $this->em->flush();
            });
            $this->em->clear();

            return $fingerprint;
        } finally {
            fclose($lock);
        }
    }

    /** @return array{0: int, 1: array<string, array<string, string>>} */
    private function loadSnapshots(string $directory): array
    {
        $generation = $this->loadReadyGeneration($directory . '/.udb_state');
        $snapshots = [];
        foreach (UdbBlock::all() as $block) {
            $snapshots[$block->letter()] = $this->loadBlock($directory, $block, $generation);
        }

        return [$generation, $snapshots];
    }

    private function loadReadyGeneration(string $path): int
    {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('The UDB directory does not contain .udb_state.');
        }

        $lines = $this->readLines($path);
        $state = [];
        foreach ($lines as $line) {
            if ('' === $line || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                throw new RuntimeException('The .udb_state file is invalid.');
            }
            [$key, $value] = explode('=', $line, 2) + [null, null];
            if (null === $key || null === $value || array_key_exists($key, $state)) {
                throw new RuntimeException('The .udb_state file is invalid.');
            }
            $state[$key] = $value;
        }

        if (5 !== count($state)
            || !array_key_exists('FORMAT', $state)
            || !array_key_exists('STATE', $state)
            || !array_key_exists('ORIGIN', $state)
            || !array_key_exists('GENERATION', $state)
            || !array_key_exists('LAST_SYNC', $state)
            || '1' !== $state['FORMAT']
            || 'READY' !== $state['STATE']
            || !in_array($state['ORIGIN'], ['FRESH', 'RECOVERY'], true)
            || 1 !== preg_match('/^[1-9][0-9]*$/', $state['GENERATION'])
            || 1 !== preg_match('/^[0-9]+$/', $state['LAST_SYNC'])) {
            throw new RuntimeException('The .udb_state file is not a valid READY UDB generation.');
        }

        return (int) $state['GENERATION'];
    }

    /** @return array<string, string> */
    private function loadBlock(string $directory, UdbBlock $block, int $generation): array
    {
        $path = sprintf('%s/udb_%s.db', $directory, $block->letter());
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException(sprintf('The UDB directory does not contain %s.', $path));
        }

        $records = [];
        $headerGeneration = null;
        $headerBlock = false;
        foreach ($this->readLines($path) as $lineNumber => $line) {
            if (sprintf('; UDB Block %s - Version 1', $block->letter()) === $line) {
                if ($headerBlock) {
                    throw new RuntimeException(sprintf('Invalid block header in %s.', $path));
                }
                $headerBlock = true;

                continue;
            }
            if (str_starts_with($line, '; Generation: ')) {
                if (null !== $headerGeneration || 1 !== preg_match('/^; Generation: ([0-9]+)$/', $line, $matches)) {
                    throw new RuntimeException(sprintf('Invalid generation header in %s.', $path));
                }
                $headerGeneration = (int) $matches[1];

                continue;
            }
            if ('' === $line || str_starts_with($line, ';')) {
                continue;
            }

            [$recordPath, $value] = explode(' ', $line, 2) + [null, null];
            if (null === $recordPath || null === $value || '' === $value || !UdbPathCodec::fitsLimits($recordPath, $value)) {
                throw new RuntimeException(sprintf('Invalid UDB record in %s at line %d.', $path, $lineNumber + 1));
            }
            $components = [];
            foreach (explode('::', $recordPath) as $component) {
                $components[] = UdbPathCodec::decodeComponent($component);
            }
            if (!UdbSchema::validate($block, $components, $value)) {
                throw new RuntimeException(sprintf('Invalid UDB schema record in %s at line %d.', $path, $lineNumber + 1));
            }
            $identity = strtolower($recordPath);
            if (array_key_exists($identity, $records)) {
                throw new RuntimeException(sprintf('Duplicate UDB record in %s at line %d.', $path, $lineNumber + 1));
            }
            $records[$identity] = [$recordPath, $value];
        }

        if (!$headerBlock || $generation !== $headerGeneration) {
            throw new RuntimeException(sprintf('The snapshot generation does not match .udb_state for %s.', $path));
        }

        $canonical = [];
        foreach ($records as [$recordPath, $value]) {
            $canonical[$recordPath] = $value;
        }

        return $canonical;
    }

    /** @param array<string, string> $rawRecords @return array<string, string> */
    private function exportRecords(array $rawRecords, UdbBlock $block): array
    {
        $records = [];
        foreach ($rawRecords as $rawPath => $value) {
            $path = UdbPathCodec::encodePath(explode('::', $rawPath));
            $components = explode('::', $rawPath);
            if (null === $path || !UdbSchema::validate($block, $components, $value)) {
                throw new RuntimeException(sprintf('Current SQL export contains an invalid %s-block record.', $block->letter()));
            }
            $records[$path] = $value;
        }

        return $records;
    }

    /** @param array<string, string> $records */
    private function checksum(array $records): string
    {
        $tuples = [];
        foreach ($records as $path => $value) {
            $tuples[] = [$path, $value];
        }

        return UdbChecksum::fromRecords($tuples);
    }

    /** @param array<string, array<string, string>> $candidate */
    private function fingerprint(array $candidate, int $generation): string
    {
        $payload = 'generation=' . $generation . "\n";
        foreach (UdbBlock::all() as $block) {
            $records = $candidate[$block->letter()];
            ksort($records, SORT_STRING);
            foreach ($records as $path => $value) {
                $payload .= $block->letter() . "\0" . $path . "\0" . $value . "\n";
            }
        }

        return hash('sha256', $payload);
    }

    /** @return list<string> */
    private function readLines(string $path): array
    {
        $file = fopen($path, 'r');
        // @codeCoverageIgnoreStart
        // The preceding regular-file check makes this only a TOCTOU failure.
        if (false === $file) {
            throw new RuntimeException(sprintf('Unable to read %s.', $path));
        }
        // @codeCoverageIgnoreEnd

        try {
            $lines = [];
            while (false !== ($line = fgets($file))) {
                if (strlen($line) > UdbPathCodec::RECORD_LINE_MAX + 1) {
                    throw new RuntimeException(sprintf('Overlong line in %s.', $path));
                }
                $lines[] = trim($line, "\r\n");
            }

            return $lines;
        } finally {
            fclose($file);
        }
    }
}
