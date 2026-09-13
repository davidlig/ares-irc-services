<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbBlockStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordMutationDoctrineStore;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Throwable;

use function sprintf;

use const DATE_ATOM;

#[CoversClass(UdbRecordMutationDoctrineStore::class)]
#[Group('integration')]
final class UdbRecordMutationDoctrineStoreTest extends DoctrineIntegrationTestCase
{
    private UdbRecordRepositoryInterface $records;

    private UdbBlockStateRepositoryInterface $states;

    private UdbRecordMutationDoctrineStore $mutations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->records = new UdbRecordDoctrineRepository($this->entityManager);
        $this->states = new UdbBlockStateDoctrineRepository($this->entityManager);
        $this->mutations = new UdbRecordMutationDoctrineStore(
            $this->entityManager,
            new MockClock('2026-09-13 16:30:00 UTC'),
        );
    }

    #[Test]
    public function insertCommitsTheRecordAndCompleteManifestTogether(): void
    {
        self::assertTrue($this->mutations->upsertWithManifest('N', 'nick::vhost', 'vhost.example'));

        self::assertSame(['nick::vhost' => 'vhost.example'], $this->records->recordsByBlock('N'));
        $manifest = $this->states->all()['N'];
        self::assertSame(
            UdbChecksum::fromRecords([['nick::vhost', 'vhost.example']]),
            $manifest->getDigest(),
        );
        self::assertSame(1, $manifest->getRecordCount());
        self::assertSame(
            new DateTimeImmutable('2026-09-13 16:30:00 UTC')->format(DATE_ATOM),
            $manifest->getModifiedAt()->format(DATE_ATOM),
        );
    }

    #[Test]
    public function changedUpdateRefreshesAnExistingManifestButNoOpDoesNot(): void
    {
        $oldModifiedAt = new DateTimeImmutable('2026-09-13 15:00:00 UTC');
        $this->records->upsert('N', 'Nick::vhost', 'old.example');
        $this->states->upsert('N', str_repeat('a', 64), 1, $oldModifiedAt);

        self::assertTrue($this->mutations->upsertWithManifest('N', 'nick::VHOST', 'new.example'));
        self::assertFalse($this->mutations->upsertWithManifest('N', 'NICK::vhost', 'new.example'));

        self::assertSame(['Nick::vhost' => 'new.example'], $this->records->recordsByBlock('N'));
        $manifest = $this->states->all()['N'];
        self::assertSame(
            UdbChecksum::fromRecords([['Nick::vhost', 'new.example']]),
            $manifest->getDigest(),
        );
        self::assertSame(1, $manifest->getRecordCount());
        self::assertSame('2026-09-13T16:30:00+00:00', $manifest->getModifiedAt()->format(DATE_ATOM));
    }

    #[Test]
    public function cascadingDeleteRefreshesTheManifestFromRemainingRecords(): void
    {
        $this->records->seedBlock('N', [
            'nick::vhost' => 'vhost.example',
            'nick::pass' => 'hash',
            'other::vhost' => 'other.example',
        ]);
        $this->states->upsert('N', str_repeat('a', 64), 3);

        self::assertTrue($this->mutations->deleteCascadeWithManifest('N', 'NICK'));
        self::assertFalse($this->mutations->deleteCascadeWithManifest('N', 'missing'));

        self::assertSame(['other::vhost' => 'other.example'], $this->records->recordsByBlock('N'));
        $manifest = $this->states->all()['N'];
        self::assertSame(
            UdbChecksum::fromRecords([['other::vhost', 'other.example']]),
            $manifest->getDigest(),
        );
        self::assertSame(1, $manifest->getRecordCount());
    }

    #[Test]
    public function expiryCompareAndDeleteIsAtomicAndRefreshesTheLinesManifest(): void
    {
        $this->records->seedBlock('K', [
            'G::*@expired.example::expires' => '*20',
            'G::*@expired.example::reason' => 'expired',
            'G::*@future.example::expires' => '*40',
            'G::*@future.example::reason' => 'future',
        ]);
        $this->states->upsert('K', str_repeat('a', 64), 4);

        self::assertFalse($this->mutations->expireLineWithManifest('G::*@expired.example', 19, 30));
        self::assertFalse($this->mutations->expireLineWithManifest('G::*@future.example', 40, 30));
        self::assertTrue($this->mutations->expireLineWithManifest('G::*@expired.example', 20, 30));

        $remaining = [
            'G::*@future.example::expires' => '*40',
            'G::*@future.example::reason' => 'future',
        ];
        self::assertSame($remaining, $this->records->recordsByBlock('K'));
        $manifest = $this->states->all()['K'];
        self::assertSame(2, $manifest->getRecordCount());
        self::assertSame(
            UdbChecksum::fromRecords([
                ['G::*@future.example::expires', '*40'],
                ['G::*@future.example::reason', 'future'],
            ]),
            $manifest->getDigest(),
        );
    }

    #[Test]
    public function numericValuesAreCanonicalBeforePersistenceAndManifestCalculation(): void
    {
        self::assertTrue($this->mutations->upsertWithManifest('K', 'G::*@host::expires', '*00020'));

        self::assertSame(['G::*@host::expires' => '*20'], $this->records->recordsByBlock('K'));
        self::assertSame(
            UdbChecksum::fromRecords([['G::*@host::expires', '*20']]),
            $this->states->all()['K']->getDigest(),
        );
    }

    #[Test]
    public function insertingNickForbidAtomicallyRemovesEverySibling(): void
    {
        $this->records->seedBlock('N', [
            'Nick::pass' => 'sha256:' . str_repeat('a', 64),
            'Nick::vhost' => 'nick.example',
            'Other::vhost' => 'other.example',
        ]);
        $this->states->upsert('N', str_repeat('a', 64), 3);

        self::assertTrue($this->mutations->upsertWithManifest('N', 'NICK::FORBID', 'reserved'));

        $expected = [
            'Other::vhost' => 'other.example',
            'NICK::FORBID' => 'reserved',
        ];
        self::assertSame($expected, $this->records->recordsByBlock('N'));
        self::assertSame(
            UdbChecksum::fromRecords([
                ['Other::vhost', 'other.example'],
                ['NICK::FORBID', 'reserved'],
            ]),
            $this->states->all()['N']->getDigest(),
        );
    }

    #[Test]
    public function manifestFailureRollsBackTheRecordAndPreservesThePriorManifest(): void
    {
        $oldDigest = UdbChecksum::fromRecords([['nick::vhost', 'old.example']]);
        $this->records->upsert('N', 'nick::vhost', 'old.example');
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
            $this->mutations->upsertWithManifest('N', 'nick::vhost', 'new.example');
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
            self::assertTrue($this->mutations->upsertWithManifest('K', sprintf('F::%s::%s', $pattern, $leaf), $value));
        }

        $manifest = $this->states->all()['K'];
        $oldDigest = $manifest->getDigest();
        $oldModifiedAt = $manifest->getModifiedAt();

        try {
            $this->mutations->upsertWithManifest('K', sprintf('F::%s::match-type', $pattern), 'regex');
            self::fail('An invalid complete regex profile must not be committed.');
        } catch (Throwable $exception) {
            self::assertStringContainsString('invalid block aggregate', $exception->getMessage());
        }

        self::assertTrue($this->entityManager->isOpen());
        self::assertTrue($this->mutations->upsertWithManifest('N', 'other::vhost', 'other.example'));
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
}
