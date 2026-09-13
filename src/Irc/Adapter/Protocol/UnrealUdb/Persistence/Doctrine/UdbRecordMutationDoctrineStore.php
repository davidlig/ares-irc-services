<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordMutationStoreInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use Closure;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Throwable;
use UnexpectedValueException;

use function count;
use function explode;
use function str_starts_with;
use function strtoupper;

/**
 * Commits each live record mutation together with the resulting block manifest.
 *
 * This deliberately owns the complete Doctrine unit of work instead of composing
 * the independent record/state repositories, whose flush/clear boundaries are
 * intended for standalone bootstrap and takeover operations.
 */
final readonly class UdbRecordMutationDoctrineStore implements UdbRecordMutationStoreInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {}

    public function upsertWithManifest(string $block, string $path, string $value): bool
    {
        $value = UdbSchema::canonicalizeValue($value);

        return $this->mutate($block, function () use ($block, $path, $value): bool {
            $siblingIds = $this->nickForbidSiblingIds($block, $path);
            $record = $this->findRecord($block, $path);
            $changed = false;

            if ($record instanceof UdbRecord) {
                if ($record->getValue() !== $value) {
                    $record->updateValue($value);
                    $changed = true;
                }
            } else {
                $this->em->persist(new UdbRecord($block, $path, $value));
                $changed = true;
            }

            if ([] !== $siblingIds) {
                $this->em
                    ->createQuery('DELETE FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.id IN (:ids)')
                    ->setParameter('ids', $siblingIds)
                    ->execute();
                $changed = true;
            }

            return $changed;
        });
    }

    public function deleteCascadeWithManifest(string $block, string $path): bool
    {
        return $this->mutate($block, function () use ($block, $path): bool {
            $ids = $this->descendantIds($block, UdbRecord::identity($path, $block));
            if ([] === $ids) {
                return false;
            }

            $this->em
                ->createQuery('DELETE FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->execute();

            return true;
        });
    }

    public function expireLineWithManifest(string $path, int $expectedExpires, int $now): bool
    {
        return $this->mutate('K', function () use ($path, $expectedExpires, $now): bool {
            if ($expectedExpires > $now) {
                return false;
            }

            $expires = $this->findRecord('K', $path . '::expires');
            if (!$expires instanceof UdbRecord || '*' . $expectedExpires !== $expires->getValue()) {
                return false;
            }

            $ids = $this->descendantIds('K', UdbRecord::identity($path, 'K'));
            $this->em
                ->createQuery('DELETE FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->execute();

            return true;
        });
    }

    /** @param Closure(): bool $operation */
    private function mutate(string $block, Closure $operation): bool
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            if (!$operation()) {
                $connection->commit();

                return false;
            }

            // Materialize inserts/updates before deriving and validating the
            // candidate manifest from the same transaction's authoritative rows.
            $this->em->flush();
            $records = $this->recordsByBlock($block);
            $blockType = UdbBlock::tryFrom($block);
            if (null === $blockType || !UdbSchema::validateAggregate($blockType, $records)) {
                throw new UnexpectedValueException('The UDB mutation would create an invalid block aggregate.');
            }
            $this->refreshManifest($block, $records);
            $this->em->flush();
            $connection->commit();

            return true;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        } finally {
            if ($this->em->isOpen()) {
                $this->em->clear();
            }
        }
    }

    private function findRecord(string $block, string $path): ?UdbRecord
    {
        $record = $this->em
            ->createQuery('SELECT r FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block AND r.identityPath = :identity')
            ->setParameter('block', $block)
            ->setParameter('identity', UdbRecord::identity($path, $block), Types::BINARY)
            ->getOneOrNullResult();

        return $record instanceof UdbRecord ? $record : null;
    }

    /** @return list<int> */
    private function descendantIds(string $block, string $identity): array
    {
        $ids = [];
        $prefix = $identity . '::';

        /** @var array<array{id: int, identityPath: string}> $rows */
        $rows = $this->em
            ->createQuery('SELECT r.id, r.identityPath FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block')
            ->setParameter('block', $block)
            ->getArrayResult();

        foreach ($rows as $row) {
            if ($row['identityPath'] === $identity || str_starts_with($row['identityPath'], $prefix)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /** @return list<int> */
    private function nickForbidSiblingIds(string $block, string $path): array
    {
        if ('N' !== strtoupper($block)) {
            return [];
        }

        $identity = UdbRecord::identity($path, $block);
        $components = explode('::', $identity);
        if (2 !== count($components) || 'forbid' !== $components[1]) {
            return [];
        }

        $ids = [];
        $prefix = $components[0] . '::';
        /** @var array<array{id: int, identityPath: string}> $rows */
        $rows = $this->em
            ->createQuery('SELECT r.id, r.identityPath FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block')
            ->setParameter('block', $block)
            ->getArrayResult();

        foreach ($rows as $row) {
            if ($row['identityPath'] !== $identity && str_starts_with($row['identityPath'], $prefix)) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /** @return array<string, string> */
    private function recordsByBlock(string $block): array
    {
        /** @var array<array{path: string, value: string}> $rows */
        $rows = $this->em
            ->createQuery('SELECT r.path, r.value FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block')
            ->setParameter('block', $block)
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            $records[$row['path']] = $row['value'];
        }

        return $records;
    }

    /** @param array<string, string> $records */
    private function refreshManifest(string $block, array $records): void
    {
        $digestRecords = [];
        foreach ($records as $path => $value) {
            $digestRecords[] = [$path, $value];
        }

        $digest = UdbChecksum::fromRecords($digestRecords);
        $modifiedAt = $this->clock->now();
        $state = $this->em
            ->createQuery('SELECT s FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState s WHERE s.block = :block')
            ->setParameter('block', $block)
            ->getOneOrNullResult();

        if ($state instanceof UdbBlockState) {
            $state->update($digest, count($records), $modifiedAt);

            return;
        }

        $this->em->persist(new UdbBlockState($block, $digest, $modifiedAt, count($records)));
    }
}
