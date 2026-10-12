<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use UnexpectedValueException;

use function substr;

final readonly class UdbRecordDoctrineRepository implements UdbRecordRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function recordsByBlock(string $block): array
    {
        /** @var array<array{path: string, value: string, valueKind: int}> $rows */
        $rows = $this->em
            ->createQuery('SELECT r.path, r.value, r.valueKind FROM App\\Irc\\Adapter\\Protocol\\UnrealUdb\\Model\\UdbRecord r WHERE r.block = :block')
            ->setParameter('block', $block)
            ->getArrayResult();
        $records = [];
        foreach ($rows as $row) {
            $kind = UdbValueKind::tryFrom($row['valueKind']);
            if (null === $kind) {
                throw new UnexpectedValueException('A persisted UDB record has an unknown value kind.');
            }
            $value = match ($kind) {
                UdbValueKind::None => UdbValue::none(),
                UdbValueKind::Number => UdbValue::number(UdbUnsignedDecimal::parse(substr($row['value'], 1)) ?? throw new UnexpectedValueException('A persisted numeric UDB value is malformed.')),
                UdbValueKind::String => UdbValue::string($row['value']),
            };
            if ($value->kind !== $kind || $value->logicalValue() !== $row['value']) {
                throw new UnexpectedValueException('A persisted UDB record value does not match its value kind.');
            }
            if (!UdbSchema::matchesStoredRecordType($block, $row['path'], $value)) {
                throw new UnexpectedValueException('A persisted UDB record does not match its canonical schema.');
            }
            $records[$row['path']] = $value;
        }

        return $records;
    }

    public function upsert(string $block, string $path, UdbValue $value): bool
    {
        if (!UdbSchema::matchesStoredRecordType($block, $path, $value)) {
            throw new UnexpectedValueException('The typed UDB record does not match its canonical schema.');
        }
        $logical = $value->logicalValue();
        $existing = $this->findRecord($block, $path);
        if ($existing instanceof UdbRecord) {
            if ($existing->getValue() === $logical && $existing->getValueKind() === $value->kind) {
                return false;
            }
            $existing->updateValue($logical, $value->kind);
        } else {
            $this->em->persist(new UdbRecord($block, $path, $logical, $value->kind));
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

    /** @param array<string, mixed> $records Untrusted bulk rows are checked before persistence. */
    public function seedBlock(string $block, array $records): void
    {
        $this->em->wrapInTransaction(function () use ($block, $records): void {
            $existing = [];
            foreach ($this->blockIdentities($block) as $identity => $id) {
                $existing[$identity] = $id;
            }

            foreach ($records as $path => $value) {
                if (!$value instanceof UdbValue) {
                    throw new UnexpectedValueException('A typed UDB seed block contains an untyped value.');
                }
                if (!UdbSchema::matchesStoredRecordType($block, $path, $value)) {
                    throw new UnexpectedValueException('A typed UDB seed block contains a schema-invalid value.');
                }
                $identity = UdbRecord::identity($path, $block);
                $id = $existing[$identity] ?? null;

                if (null !== $id) {
                    $record = $this->em->find(UdbRecord::class, $id);
                    if ($record instanceof UdbRecord) {
                        $record->updateValue($value->logicalValue(), $value->kind);
                    }
                } else {
                    $this->em->persist(new UdbRecord($block, $path, $value->logicalValue(), $value->kind));
                }
            }

            $this->em->flush();
        });

        $this->em->clear();
    }

    /** @param array<string, mixed> $records Untrusted bulk rows are checked before persistence. */
    public function replaceBlock(string $block, array $records): void
    {
        $this->em->wrapInTransaction(function () use ($block, $records): void {
            $this->em
                ->createQuery('DELETE FROM App\\Irc\\Adapter\\Protocol\\UnrealUdb\\Model\\UdbRecord r WHERE r.block = :block')
                ->setParameter('block', $block)
                ->execute();
            foreach ($records as $path => $value) {
                if (!$value instanceof UdbValue) {
                    throw new UnexpectedValueException('A typed UDB block contains an untyped value.');
                }
                if (!UdbSchema::matchesStoredRecordType($block, $path, $value)) {
                    throw new UnexpectedValueException('A typed UDB block contains a schema-invalid value.');
                }
                $this->em->persist(new UdbRecord($block, $path, $value->logicalValue(), $value->kind));
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

    /**
     * @return array<string, int> identity path => record id
     */
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
