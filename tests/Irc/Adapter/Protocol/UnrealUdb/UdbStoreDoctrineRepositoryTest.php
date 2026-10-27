<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbAuthorityStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbBlockStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use UnexpectedValueException;

use function count;
use function str_repeat;

use const DATE_ATOM;

#[CoversClass(UdbRecordDoctrineRepository::class)]
#[CoversClass(UdbBlockStateDoctrineRepository::class)]
#[CoversClass(UdbAuthorityStateDoctrineRepository::class)]
#[Group('integration')]
final class UdbStoreDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private UdbRecordRepositoryInterface $records;

    private UdbBlockStateRepositoryInterface $states;

    private UdbAuthorityStateRepositoryInterface $authority;

    #[Test]
    public function mappedBinaryValueHydratesEntityAndScalarProjectionWithoutByteLoss(): void
    {
        $bytes = "fixture\x01\xff\x80\\x4142";
        self::assertTrue($this->upsert('N', 'alice::swhois', $bytes));
        $this->flushAndClear();
        self::assertSame(['alice::swhois' => $bytes], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        $entity = $this->entityManager->getRepository(UdbRecord::class)->findOneBy(['path' => 'alice::swhois']);
        self::assertInstanceOf(UdbRecord::class, $entity);
        self::assertSame($bytes, $entity->getValue());
        self::assertSame('binary', $this->entityManager->getClassMetadata(UdbRecord::class)->getTypeOfField('value'));
        $rows = $this->entityManager->createQuery('SELECT r.value FROM App\\Irc\\Adapter\\Protocol\\UnrealUdb\\Model\\UdbRecord r')->getScalarResult();
        self::assertSame([['value' => $bytes]], $rows);
    }

    #[Test]
    public function channelAccessUpsertSeedAndReplacePreserveExplicitKindsAndBytes(): void
    {
        $rows = [
            '#chan::access::alice' => UdbValue::none(),
            '#chan::access::bob' => UdbValue::string('*1'),
            '#chan::access::carol' => UdbValue::number(UdbUnsignedDecimal::fromInt(1)),
            '#chan::access::dave' => UdbValue::string('*level'),
        ];
        foreach (['upsert', 'seedBlock', 'replaceBlock'] as $method) {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM udb_records');
            if ('upsert' === $method) {
                foreach ($rows as $path => $value) {
                    self::assertTrue($this->records->upsert('C', $path, $value));
                }
            } else {
                $this->records->$method('C', $rows);
            }
            $this->entityManager->clear();
            $loaded = $this->records->recordsByBlock('C');
            self::assertCount(count($rows), $loaded);
            foreach ($rows as $path => $value) {
                self::assertSame($value->kind, $loaded[$path]->kind);
                self::assertSame($value->value, $loaded[$path]->value);
                self::assertSame($value->logicalValue(), $loaded[$path]->logicalValue());
            }
        }
        self::assertTrue($this->records->upsert('C', '#chan::access::bob', UdbValue::number(UdbUnsignedDecimal::fromInt(1))));
        self::assertSame(UdbValue::number(UdbUnsignedDecimal::fromInt(1))->kind, $this->records->recordsByBlock('C')['#chan::access::bob']->kind);
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function invalidPersistedSchemaRows(): iterable
    {
        yield 'forbidden modes' => ['C', '#chan::modes', '+r', 1];
        yield 'nul string' => ['N', 'nick::swhois', "synthetic\0payload", 1];
        yield 'kind incompatible with path' => ['N', 'nick::pass', '*1', 2];
        yield 'none line prefix' => ['K', 'G::*@host', '', 0];
        yield 'invalid key' => ['N', 'nick::unknown', 'synthetic-payload', 1];
        yield 'noncanonical path' => ['N', 'ni%63k::swhois', 'synthetic-payload', 1];
    }

    #[Test]
    #[DataProvider('invalidPersistedSchemaRows')]
    public function readbackRejectsInvalidExistingRowsWithoutChangingSql(string $block, string $path, string $raw, int $kind): void
    {
        $connection = $this->entityManager->getConnection();
        $this->entityManager->persist(new UdbRecord('N', 'nick::swhois', 'valid fixture'));
        $this->entityManager->flush();
        $connection->executeStatement('UPDATE udb_records SET block = ?, path = ?, value = ?, value_kind = ?', [$block, $path, $raw, $kind]);
        $this->entityManager->clear();
        $before = $connection->fetchAllAssociative('SELECT * FROM udb_records');
        try {
            $this->records->recordsByBlock($block);
            self::fail('Invalid persisted schema rows must not be returned.');
        } catch (UnexpectedValueException $error) {
            self::assertSame('A persisted UDB record does not match its canonical schema.', $error->getMessage());
        }
        self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM udb_records'));
    }

    #[Test]
    public function upsertInsertsAndUpdatesCaseInsensitively(): void
    {
        self::assertTrue($this->upsert('N', 'DavidLig::vhost', 'first.example.net'));
        self::assertTrue($this->upsert('N', 'davidlig::VHOST', 'second.example.net'));
        self::assertFalse($this->upsert('N', 'DAVIDLIG::vhost', 'second.example.net'));
        $this->flushAndClear();

        self::assertSame(['DavidLig::vhost' => 'second.example.net'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
    }

    #[Test]
    public function kFilterPatternIdentityIsCaseSensitiveButItsLeafIsNot(): void
    {
        self::assertTrue($this->upsert('K', 'F::b64%3AQWJj::reason', 'first'));
        self::assertTrue($this->upsert('K', 'F::b64%3AQWJk::reason', 'second'));
        self::assertTrue($this->upsert('K', 'f::b64%3AQWJj::REASON', 'updated'));
        self::assertFalse($this->upsert('K', 'F::b64%3AQWJj::reason', 'updated'));
        $this->flushAndClear();

        self::assertSame([
            'F::b64%3AQWJj::reason' => 'updated',
            'F::b64%3AQWJk::reason' => 'second',
        ], UdbTestValues::logicalMap($this->records->recordsByBlock('K')));

        self::assertTrue($this->records->deleteCascade('K', 'f::b64%3AQWJj'));
        self::assertSame(
            ['F::b64%3AQWJk::reason' => 'second'],
            UdbTestValues::logicalMap($this->records->recordsByBlock('K')),
        );
    }

    #[Test]
    public function recordsByBlockIsolatesBlocks(): void
    {
        $this->upsert('N', 'nick::pass', 'crypt:sha256:abc');
        $this->upsert('S', 'nickserv', 'NickServ!NickServ@services');
        $this->flushAndClear();

        self::assertSame(['nick::pass' => 'crypt:sha256:abc'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        self::assertSame(['nickserv' => 'NickServ!NickServ@services'], UdbTestValues::logicalMap($this->records->recordsByBlock('S')));
        self::assertSame([], UdbTestValues::logicalMap($this->records->recordsByBlock('L')));
    }

    #[Test]
    public function deleteCascadeRemovesTheRecordAndAllDescendants(): void
    {
        $this->upsert('N', 'DavidLig::vhost', 'v');
        $this->upsert('N', 'davidlig::pass', 'crypt:p');
        $this->upsert('N', 'other::vhost', 'v');
        $this->upsert('C', '#chan::founder', 'DavidLig');
        $this->flushAndClear();

        self::assertTrue($this->records->deleteCascade('N', 'DAVIDLIG'));
        $this->flushAndClear();

        self::assertSame(['other::vhost' => 'v'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        self::assertSame(['#chan::founder' => 'DavidLig'], UdbTestValues::logicalMap($this->records->recordsByBlock('C')));
    }

    #[Test]
    public function deleteCascadeOfUnknownPathsIsANoOp(): void
    {
        $this->upsert('N', 'nick::vhost', 'v');
        $this->flushAndClear();

        self::assertFalse($this->records->deleteCascade('N', 'missing'));
        $this->flushAndClear();

        self::assertSame(['nick::vhost' => 'v'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
    }

    #[Test]
    public function replaceBlockReplacesTheBlockWithoutTouchingOtherBlocks(): void
    {
        $this->upsert('N', 'old::vhost', 'stale.example.net');
        $this->upsert('N', 'old::pass', 'crypt:stale-hash');
        $this->upsert('S', 'propagator', 'hub1.example');
        $this->flushAndClear();

        $this->replaceBlock('N', ['fresh::vhost' => 'new.example.net']);
        $this->flushAndClear();

        self::assertSame(['fresh::vhost' => 'new.example.net'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        self::assertSame(['propagator' => 'hub1.example'], UdbTestValues::logicalMap($this->records->recordsByBlock('S')));
    }

    #[Test]
    public function seedBlockMergesWithoutLosingExistingRecords(): void
    {
        $this->upsert('N', 'nick::vhost', 'raw-value');
        $this->flushAndClear();

        $this->seedBlock('N', ['nick::pass' => 'crypt:sha256:abc', 'nick::vhost' => 'seed-value']);
        $this->flushAndClear();

        self::assertSame([
            'nick::vhost' => 'seed-value',
            'nick::pass' => 'crypt:sha256:abc',
        ], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
    }

    #[Test]
    public function seedBlockIsCaseInsensitiveOnIdentities(): void
    {
        $this->upsert('N', 'Nick::Pass', 'crypt:old');
        $this->flushAndClear();

        $this->seedBlock('N', ['nick::pass' => 'crypt:new']);
        $this->flushAndClear();

        self::assertCount(1, UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        self::assertSame(['Nick::Pass' => 'crypt:new'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
    }

    #[Test]
    public function seedBlockPreservesDistinctKFilterPatterns(): void
    {
        $this->upsert('K', 'F::b64%3AQWJj::reason', 'old');
        $this->flushAndClear();

        $this->seedBlock('K', [
            'f::b64%3AQWJj::REASON' => 'updated',
            'F::b64%3AQWJk::reason' => 'distinct',
        ]);
        $this->flushAndClear();

        self::assertSame([
            'F::b64%3AQWJj::reason' => 'updated',
            'F::b64%3AQWJk::reason' => 'distinct',
        ], UdbTestValues::logicalMap($this->records->recordsByBlock('K')));
    }

    #[Test]
    public function blockStatesAreUpsertedPerBlock(): void
    {
        $firstModifiedAt = new DateTimeImmutable('2026-09-13 10:00:00');
        $secondModifiedAt = new DateTimeImmutable('2026-09-13 11:00:00');
        $this->states->upsert('S', str_repeat('a', 64), 1, $firstModifiedAt);
        $this->states->upsert('S', str_repeat('b', 64), 2, $secondModifiedAt);
        $this->states->upsert('L', str_repeat('0', 64), 0, $firstModifiedAt);
        $this->flushAndClear();

        $all = $this->states->all();
        self::assertCount(2, $all);
        self::assertSame(str_repeat('b', 64), $all['S']->getChecksum());
        self::assertSame(2, $all['S']->getRecordCount());
        self::assertSame($secondModifiedAt->format(DATE_ATOM), $all['S']->getModifiedAt()->format(DATE_ATOM));
        self::assertSame(str_repeat('0', 64), $all['L']->getChecksum());
        self::assertSame(0, $all['L']->getRecordCount());
    }

    #[Test]
    public function recordsPersistIdentityAndTimestamps(): void
    {
        $this->upsert('I', '1.2.3.4::clones', '*5');
        $this->flushAndClear();

        $stored = $this->entityManager
            ->createQuery('SELECT r FROM App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord r WHERE r.block = :block')
            ->setParameter('block', 'I')
            ->getSingleResult();

        self::assertInstanceOf(UdbRecord::class, $stored);
        self::assertSame('1.2.3.4::clones', $stored->getPath());
        self::assertSame('1.2.3.4::clones', $stored->getIdentityPath());
        self::assertSame('*5', $stored->getValue());
    }

    #[Test]
    public function authorityRequiresApprovalAndPersistsItsFingerprint(): void
    {
        self::assertFalse($this->authority->isApproved());

        $this->authority->approve(str_repeat('b', 64));
        $this->flushAndClear();

        self::assertTrue($this->authority->isApproved());
        self::assertSame(str_repeat('b', 64), $this->authority->state()->getFingerprint());
        self::assertSame(UdbAuthorityState::CURRENT_PROTOCOL_REVISION, $this->authority->state()->getProtocolRevision());

        $this->authority->revoke();
        self::assertFalse($this->authority->isApproved());
    }

    #[Test]
    public function corruptSqlKindsAndMismatchedLogicalBytesAreRejected(): void
    {
        foreach ([[255, 'value'], [0, 'value'], [2, '*bad']] as [$kind, $raw]) {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM udb_records');
            $this->entityManager->persist(new UdbRecord('N', 'nick::vhost', 'fixture.test'));
            $this->entityManager->flush();
            $this->entityManager->getConnection()->executeStatement('UPDATE udb_records SET value_kind = ?, value = ?', [$kind, $raw]);
            $this->entityManager->clear();
            try {
                $this->records->recordsByBlock('N');
                self::fail('Corrupt persisted logical values must not be returned.');
            } catch (UnexpectedValueException $error) {
                self::assertStringContainsString('persisted', $error->getMessage());
            }
        }
    }

    #[Test]
    public function invalidTypedUpsertIsRejectedBeforeChangingSql(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->records->upsert('N', 'nick::pass', UdbValue::none());
    }

    #[Test]
    public function untrustedBulkSeedsAndReplacementsRejectUntypedAndSchemaInvalidRows(): void
    {
        foreach (['seedBlock', 'replaceBlock'] as $method) {
            foreach (['raw', UdbValue::none()] as $value) {
                $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $this->entityManager->getConfiguration());
                new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());
                try {
                    $repository = new UdbRecordDoctrineRepository($em);
                    $repository->$method('N', ['nick::pass' => $value]);
                    self::fail('Invalid seed must be rolled back.');
                } catch (UnexpectedValueException $error) {
                    self::assertStringContainsString('typed', $error->getMessage());
                } finally {
                    $em->getConnection()->close();
                }
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new UdbRecordDoctrineRepository($this->entityManager);
        $this->states = new UdbBlockStateDoctrineRepository($this->entityManager);
        $this->authority = new UdbAuthorityStateDoctrineRepository($this->entityManager);
    }

    private function upsert(string $block, string $path, string $logicalValue): bool
    {
        return $this->records->upsert($block, $path, UdbTestValues::fromLogical(UdbBlock::fromLetter($block) ?? throw new LogicException('Invalid fixture block.'), $path, $logicalValue));
    }

    /**
     * @param array<string, string> $records
     */
    private function seedBlock(string $block, array $records): void
    {
        $this->records->seedBlock($block, UdbTestValues::typedMap(UdbBlock::fromLetter($block) ?? throw new LogicException('Invalid fixture block.'), $records));
    }

    /**
     * @param array<string, string> $records
     */
    private function replaceBlock(string $block, array $records): void
    {
        $this->records->replaceBlock($block, UdbTestValues::typedMap(UdbBlock::fromLetter($block) ?? throw new LogicException('Invalid fixture block.'), $records));
    }
}
