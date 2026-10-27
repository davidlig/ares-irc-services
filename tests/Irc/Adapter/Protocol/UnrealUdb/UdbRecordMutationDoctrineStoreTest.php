<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbBlockStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordMutationDoctrineStore;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\Clock\MockClock;
use Throwable;
use UnexpectedValueException;

use function sprintf;
use function str_repeat;

use const DATE_ATOM;

#[CoversClass(UdbRecordMutationDoctrineStore::class)]
#[Group('integration')]
final class UdbRecordMutationDoctrineStoreTest extends DoctrineIntegrationTestCase
{
    private UdbRecordRepositoryInterface $records;

    private UdbBlockStateRepositoryInterface $states;

    private UdbRecordMutationDoctrineStore $mutations;

    private UdbStreamOrigin $origin;

    #[Test]
    public function binaryMutationManifestAndReloadUseTheSameLogicalBytes(): void
    {
        $bytes = "fixture\x01\xff\x80\\x4142";
        self::assertSame('1', (string) $this->upsert('N', 'alice::swhois', $bytes));
        $before = $this->states->all()['N']->getChecksum();
        $this->entityManager->clear();
        self::assertSame(['alice::swhois' => $bytes], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        self::assertSame($before, $this->states->all()['N']->getChecksum());
        self::assertNull($this->upsert('N', 'ALICE::SWHOIS', $bytes));
    }

    #[Test]
    public function channelAccessMutationManifestRetainsExplicitKindsAndBytes(): void
    {
        $rows = [
            '#chan::access::alice' => UdbValue::none(),
            '#chan::access::bob' => UdbValue::string('*1'),
            '#chan::access::carol' => UdbValue::number(UdbUnsignedDecimal::fromInt(1)),
            '#chan::access::dave' => UdbValue::string('*level'),
        ];
        $sequence = 0;
        foreach ($rows as $path => $value) {
            self::assertSame((string) ++$sequence, (string) $this->mutations->upsertWithManifest('C', $path, $value, $this->origin));
        }
        $this->entityManager->clear();
        $loaded = $this->records->recordsByBlock('C');
        self::assertCount(4, $loaded);
        foreach ($rows as $path => $value) {
            self::assertSame($value->kind, $loaded[$path]->kind);
            self::assertSame($value->value, $loaded[$path]->value);
        }
        $manifest = $this->states->all()['C'];
        self::assertSame(4, $manifest->getRecordCount());
        self::assertSame(UdbChecksum::fromMap(UdbBlock::Channels, $rows), $manifest->getChecksum());
        self::assertNull($this->mutations->upsertWithManifest('C', '#chan::access::bob', UdbValue::string('*1'), $this->origin));
    }

    #[Test]
    public function insertCommitsTheRecordAndCompleteManifestTogether(): void
    {
        self::assertSame('1', (string) $this->upsert('N', 'nick::vhost', 'vhost.example'));

        self::assertSame(['nick::vhost' => 'vhost.example'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        $manifest = $this->states->all()['N'];
        self::assertSame(
            UdbTestValues::digest(UdbBlock::Nicks, ['nick::vhost' => 'vhost.example']),
            $manifest->getDigest(),
        );
        self::assertSame(1, $manifest->getRecordCount());
        self::assertSame(
            new DateTimeImmutable('2026-09-13 16:30:00 UTC')->format(DATE_ATOM),
            $manifest->getModifiedAt()->format(DATE_ATOM),
        );
    }

    #[Test]
    public function changedMutationsShareOnePersistedSequenceAndDropDoesNotDeleteTheRecord(): void
    {
        self::assertSame('1', (string) $this->upsert('N', 'alice::vhost', 'a.example'));
        self::assertSame('2', (string) $this->upsert('C', '#chat::topic', 'welcome'));
        self::assertNull($this->upsert('N', 'alice::vhost', 'a.example'));
        $this->records->upsert('I', '1.2.3.4::clones', UdbValue::number(UdbUnsignedDecimal::fromInt(5)));
        self::assertSame('3', (string) $this->mutations->allocateBlockDropSequence('I', $this->origin));

        self::assertSame(
            ['1.2.3.4::clones' => '*5'],
            UdbTestValues::logicalMap($this->records->recordsByBlock('I')),
        );
        $state = $this->entityManager->find(UdbAuthorityState::class, 1);
        self::assertInstanceOf(UdbAuthorityState::class, $state);
        $checkpoint = $state->getDatasetCheckpoint();
        self::assertNotNull($checkpoint);
        self::assertSame('002', $checkpoint->origin->sid);
        self::assertSame('0123456789abcdef', $checkpoint->origin->epoch);
        self::assertSame('3', (string) $checkpoint->watermark);
    }

    #[Test]
    public function freshProcessEpochResetsOnlyTheLocalDatasetCheckpointAndRetainsImportedProvenance(): void
    {
        $imported = new UdbDatasetCheckpoint(
            new UdbStreamOrigin('001', '1111111111111111'),
            UdbUnsignedDecimal::fromInt(41),
        );
        $state = $this->entityManager->find(UdbAuthorityState::class, 1);
        self::assertInstanceOf(UdbAuthorityState::class, $state);
        $state->recordImportedCheckpoint($imported);
        $this->entityManager->persist($state);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $nextOrigin = new UdbStreamOrigin('002', '2222222222222222');
        $local = $this->mutations->beginLocalStream($nextOrigin);
        $persisted = $this->entityManager->find(UdbAuthorityState::class, 1);

        self::assertSame('0', (string) $local->watermark);
        self::assertInstanceOf(UdbAuthorityState::class, $persisted);
        self::assertSame('41', (string) $persisted->getImportedCheckpoint()?->watermark);
        self::assertSame('0', (string) $persisted->getDatasetCheckpoint()?->watermark);
        self::assertSame('2222222222222222', $persisted->getDatasetCheckpoint()?->origin->epoch);
    }

    #[Test]
    public function changedUpdateRefreshesAnExistingManifestButNoOpDoesNot(): void
    {
        $oldModifiedAt = new DateTimeImmutable('2026-09-13 15:00:00 UTC');
        $this->records->upsert('N', 'Nick::vhost', UdbValue::string('old.example'));
        $this->states->upsert('N', str_repeat('a', 64), 1, $oldModifiedAt);

        self::assertNotNull($this->upsert('N', 'nick::VHOST', 'new.example'));
        self::assertNull($this->upsert('N', 'NICK::vhost', 'new.example'));

        self::assertSame(['Nick::vhost' => 'new.example'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        $manifest = $this->states->all()['N'];
        self::assertSame(
            UdbTestValues::digest(UdbBlock::Nicks, ['Nick::vhost' => 'new.example']),
            $manifest->getDigest(),
        );
        self::assertSame(1, $manifest->getRecordCount());
        self::assertSame('2026-09-13T16:30:00+00:00', $manifest->getModifiedAt()->format(DATE_ATOM));
    }

    #[Test]
    public function cascadingDeleteRefreshesTheManifestFromRemainingRecords(): void
    {
        $this->records->seedBlock('N', UdbTestValues::typedMap(UdbBlock::Nicks, [
            'nick::vhost' => 'vhost.example', 'nick::pass' => 'crypt:hash', 'other::vhost' => 'other.example',
        ]));
        $this->states->upsert('N', str_repeat('a', 64), 3);

        self::assertNotNull($this->mutations->deleteCascadeWithManifest('N', 'NICK', $this->origin));
        self::assertNull($this->mutations->deleteCascadeWithManifest('N', 'missing', $this->origin));

        self::assertSame(['other::vhost' => 'other.example'], UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        $manifest = $this->states->all()['N'];
        self::assertSame(
            UdbTestValues::digest(UdbBlock::Nicks, ['other::vhost' => 'other.example']),
            $manifest->getDigest(),
        );
        self::assertSame(1, $manifest->getRecordCount());
    }

    #[Test]
    public function expiryCompareAndDeleteIsAtomicAndRefreshesTheLinesManifest(): void
    {
        $this->records->seedBlock('K', UdbTestValues::typedMap(UdbBlock::Lines, [
            'G::*@expired.example::expires' => '*20',
            'G::*@expired.example::reason' => 'expired',
            'G::*@future.example::expires' => '*40',
            'G::*@future.example::reason' => 'future',
        ]));
        $this->states->upsert('K', str_repeat('a', 64), 4);

        self::assertNull($this->mutations->expireLineWithManifest('G::*@expired.example', 19, 30, $this->origin));
        self::assertNull($this->mutations->expireLineWithManifest('G::*@future.example', 40, 30, $this->origin));
        self::assertNotNull($this->mutations->expireLineWithManifest('G::*@expired.example', 20, 30, $this->origin));

        $remaining = [
            'G::*@future.example::expires' => '*40',
            'G::*@future.example::reason' => 'future',
        ];
        self::assertSame($remaining, UdbTestValues::logicalMap($this->records->recordsByBlock('K')));
        $manifest = $this->states->all()['K'];
        self::assertSame(2, $manifest->getRecordCount());
        self::assertSame(
            UdbTestValues::digest(UdbBlock::Lines, $remaining),
            $manifest->getDigest(),
        );
    }

    #[Test]
    public function numericValuesAreCanonicalBeforePersistenceAndManifestCalculation(): void
    {
        self::assertNotNull($this->upsert('K', 'G::*@host::expires', '*00020'));

        self::assertSame(['G::*@host::expires' => '*20'], UdbTestValues::logicalMap($this->records->recordsByBlock('K')));
        self::assertSame(
            UdbTestValues::digest(UdbBlock::Lines, ['G::*@host::expires' => '*20']),
            $this->states->all()['K']->getDigest(),
        );
    }

    #[Test]
    public function insertingNickForbidAtomicallyRemovesEverySibling(): void
    {
        $this->records->seedBlock('N', UdbTestValues::typedMap(UdbBlock::Nicks, [
            'Nick::pass' => 'sha256:' . str_repeat('a', 64),
            'Nick::vhost' => 'nick.example',
            'Other::vhost' => 'other.example',
        ]));
        $this->states->upsert('N', str_repeat('a', 64), 3);

        self::assertNotNull($this->upsert('N', 'NICK::FORBID', 'reserved'));

        $expected = [
            'Other::vhost' => 'other.example',
            'NICK::FORBID' => 'reserved',
        ];
        self::assertSame($expected, UdbTestValues::logicalMap($this->records->recordsByBlock('N')));
        self::assertSame(
            UdbTestValues::digest(UdbBlock::Nicks, $expected),
            $this->states->all()['N']->getDigest(),
        );
    }

    #[Test]
    public function manifestFailureRollsBackTheRecordAndPreservesThePriorManifest(): void
    {
        $oldDigest = UdbTestValues::digest(UdbBlock::Nicks, ['nick::vhost' => 'old.example']);
        $this->records->upsert('N', 'nick::vhost', UdbValue::string('old.example'));
        $this->states->upsert('N', $oldDigest, 1, new DateTimeImmutable('2026-09-13 15:00:00 UTC'));
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(<<<'SQL'
            CREATE TRIGGER reject_udb_manifest_update
            BEFORE UPDATE ON udb_block_states
            BEGIN
                SELECT RAISE(ABORT, 'manifest write failed');
            END
            SQL);

        try {
            $this->upsert('N', 'nick::vhost', 'new.example');
            self::fail('The injected manifest failure must escape the persistence boundary.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('manifest write failed', $exception->getMessage());
        }

        self::assertSame(
            'old.example',
            $connection->fetchOne("SELECT value FROM udb_records WHERE block = 'N' AND path = 'nick::vhost'"),
        );
        self::assertSame(
            ['checksum' => $oldDigest, 'record_count' => 1],
            $connection->fetchAssociative("SELECT checksum, record_count FROM udb_block_states WHERE block = 'N'"),
        );
    }

    #[Test]
    public function invalidCompleteRegexSpamfilterRollsBackWithoutChangingItsManifest(): void
    {
        $pattern = 'b64%3AKA=='; // base64("("): valid for simple matching, invalid PCRE.
        foreach ([
            'match-type' => 'simple',
            'targets' => 'c',
            'action' => 'kill',
            'reason' => 'blocked',
        ] as $leaf => $value) {
            self::assertNotNull($this->upsert('K', sprintf('F::%s::%s', $pattern, $leaf), $value));
        }

        $manifest = $this->states->all()['K'];
        $oldDigest = $manifest->getDigest();
        $oldModifiedAt = $manifest->getModifiedAt();

        try {
            $this->upsert('K', sprintf('F::%s::match-type', $pattern), 'regex');
            self::fail('An invalid complete regex profile must not be committed.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('invalid block aggregate', $exception->getMessage());
        }

        self::assertTrue($this->entityManager->isOpen());
        self::assertNotNull($this->upsert('N', 'other::vhost', 'other.example'));
        self::assertSame(
            'simple',
            $this->entityManager->getConnection()->fetchOne(
                "SELECT value FROM udb_records WHERE block = 'K' AND path = :path",
                ['path' => sprintf('F::%s::match-type', $pattern)],
            ),
        );
        $preservedManifest = $this->states->all()['K'];
        self::assertSame($oldDigest, $preservedManifest->getDigest());
        self::assertSame($oldModifiedAt->format(DATE_ATOM), $preservedManifest->getModifiedAt()->format(DATE_ATOM));
    }

    #[Test]
    public function blockDropAllocatesMonotonicCreditWithoutDeletingRecords(): void
    {
        self::assertSame('1', (string) $this->mutations->allocateBlockDropSequence('I', $this->origin));
        try {
            $this->mutations->allocateBlockDropSequence('?', $this->origin);
            self::fail('Unknown block cannot consume a mutation sequence.');
        } catch (UnexpectedValueException $error) {
            self::assertStringContainsString('canonical block', $error->getMessage());
        }
        self::assertSame('2', (string) $this->mutations->allocateBlockDropSequence('I', $this->origin));
    }

    #[Test]
    public function beginStreamSqlFailureRollsBackAndClearsItsUnitOfWork(): void
    {
        $this->entityManager->getConnection()->executeStatement('DROP TABLE udb_authority_state');
        try {
            $this->mutations->beginLocalStream($this->origin);
            self::fail('Missing private SQL table must abort stream initialization.');
        } catch (Exception $error) {
            self::assertNotSame('', $error->getMessage());
        }
        self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
    }

    #[Test]
    public function blockDropOriginFailureDoesNotConsumeSequence(): void
    {
        try {
            $this->mutations->allocateBlockDropSequence('I', new UdbStreamOrigin('001', str_repeat('1', 16)));
            self::fail('Foreign process origin must not allocate a local drop.');
        } catch (UnexpectedValueException $error) {
            self::assertStringContainsString('origin', $error->getMessage());
        }
        self::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        self::assertSame('1', (string) $this->mutations->allocateBlockDropSequence('I', $this->origin));
    }

    #[Test]
    public function corruptStoredKindIsRejectedDuringManifestDerivation(): void
    {
        $this->upsert('N', 'nick::vhost', 'fixture.test');
        $this->entityManager->getConnection()->executeStatement('UPDATE udb_records SET value_kind = 255');
        $this->entityManager->clear();
        $this->expectException(UnexpectedValueException::class);
        new ReflectionMethod(UdbRecordMutationDoctrineStore::class, 'recordsByBlock')->invoke($this->mutations, 'N');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->records = new UdbRecordDoctrineRepository($this->entityManager);
        $this->states = new UdbBlockStateDoctrineRepository($this->entityManager);
        $this->mutations = new UdbRecordMutationDoctrineStore(
            $this->entityManager,
            new MockClock('2026-09-13 16:30:00 UTC'),
        );
        $this->origin = new UdbStreamOrigin('002', '0123456789abcdef');
        $this->mutations->beginLocalStream($this->origin);
    }

    private function upsert(string $block, string $path, string $logicalValue): ?UdbUnsignedDecimal
    {
        $value = UdbTestValues::fromLogical(UdbBlock::fromLetter($block) ?? throw new LogicException('Invalid fixture block.'), $path, $logicalValue);

        return $this->mutations->upsertWithManifest($block, $path, $value, $this->origin);
    }
}
