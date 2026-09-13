<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UdbRecordDoctrineRepository implements UdbRecordRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function recordsByBlock(string $block): array
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

    public function upsert(string $block, string $path, string $value): bool
    {
        $value = UdbSchema::canonicalizeValue($value);
        $existing = $this->findRecord($block, $path);

        if ($existing instanceof UdbRecord) {
            if ($existing->getValue() === $value) {
                return false;
            }

            $existing->updateValue($value);
        } else {
            $this->em->persist(new UdbRecord($block, $path, $value));
        }

        $this->em->flush();
        $this->em->clear();

        return true;
    }

    public function deleteCascade(string $block, string $path): bool
    {
        $identity = UdbRecord::identity($path, $block);
        $ids = $this->descendantIds($block, $identity);

        if ([] === $ids) {
            return false;
        }

        $this->em
            ->createQuery('DELETE FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->execute();

        $this->em->clear();

        return true;
    }

    public function seedBlock(string $block, array $records): void
    {
        $this->em->wrapInTransaction(function () use ($block, $records): void {
            $existing = [];
            foreach ($this->blockIdentities($block) as $identity => $id) {
                $existing[$identity] = $id;
            }

            foreach ($records as $path => $value) {
                $identity = UdbRecord::identity($path, $block);
                $id = $existing[$identity] ?? null;

                if (null !== $id) {
                    $record = $this->em->find(UdbRecord::class, $id);
                    if ($record instanceof UdbRecord) {
                        $record->updateValue($value);
                    }
                } else {
                    $this->em->persist(new UdbRecord($block, $path, $value));
                }
            }

            $this->em->flush();
        });

        $this->em->clear();
    }

    public function replaceBlock(string $block, array $records): void
    {
        $this->em->wrapInTransaction(function () use ($block, $records): void {
            $this->em
                ->createQuery('DELETE FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block')
                ->setParameter('block', $block)
                ->execute();

            foreach ($records as $path => $value) {
                $this->em->persist(new UdbRecord($block, $path, $value));
            }

            $this->em->flush();
        });

        $this->em->clear();
    }

    private function findRecord(string $block, string $path): ?UdbRecord
    {
        $record = $this->em
            ->createQuery('SELECT r FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block AND r.identityHash = :identity')
            ->setParameter('block', $block)
            ->setParameter('identity', UdbRecord::identityHash(UdbRecord::identity($path, $block)), Types::BINARY)
            ->getOneOrNullResult();

        return $record instanceof UdbRecord ? $record : null;
    }

    /**
     * IDs of the record at the identity and every descendant identity
     * ("identity::..."), matched case-insensitively in PHP so the behavior
     * is identical on MariaDB and SQLite.
     *
     * @return list<int>
     */
    private function descendantIds(string $block, string $identity): array
    {
        $ids = [];
        $prefix = $identity . '::';

        foreach ($this->blockIdentities($block) as $candidate => $id) {
            if ($candidate === $identity || str_starts_with($candidate, $prefix)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @return array<string, int> identity path => record id */
    private function blockIdentities(string $block): array
    {
        /** @var array<array{id: int, identityPath: string}> $rows */
        $rows = $this->em
            ->createQuery('SELECT r.id, r.identityPath FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block')
            ->setParameter('block', $block)
            ->getArrayResult();

        $identities = [];
        foreach ($rows as $row) {
            $identities[$row['identityPath']] = $row['id'];
        }

        return $identities;
    }
}
