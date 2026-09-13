<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbAuthorityStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbBlockStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->records = new UdbRecordDoctrineRepository($this->entityManager);
        $this->states = new UdbBlockStateDoctrineRepository($this->entityManager);
        $this->authority = new UdbAuthorityStateDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function upsertInsertsAndUpdatesCaseInsensitively(): void
    {
        self::assertTrue($this->records->upsert('N', 'DavidLig::vhost', 'first.example.net'));
        self::assertTrue($this->records->upsert('N', 'davidlig::VHOST', 'second.example.net'));
        self::assertFalse($this->records->upsert('N', 'DAVIDLIG::vhost', 'second.example.net'));
        $this->flushAndClear();

        self::assertSame(['DavidLig::vhost' => 'second.example.net'], $this->records->recordsByBlock('N'));
    }

    #[Test]
    public function kFilterPatternIdentityIsCaseSensitiveButItsLeafIsNot(): void
    {
        self::assertTrue($this->records->upsert('K', 'F::b64:QWJj::reason', 'first'));
        self::assertTrue($this->records->upsert('K', 'F::b64:qwjj::reason', 'second'));
        self::assertTrue($this->records->upsert('K', 'f::b64:QWJj::REASON', 'updated'));
        self::assertFalse($this->records->upsert('K', 'F::b64:QWJj::reason', 'updated'));
        $this->flushAndClear();

        self::assertSame([
            'F::b64:QWJj::reason' => 'updated',
            'F::b64:qwjj::reason' => 'second',
        ], $this->records->recordsByBlock('K'));

        self::assertTrue($this->records->deleteCascade('K', 'f::b64:QWJj'));
        self::assertSame(
            ['F::b64:qwjj::reason' => 'second'],
            $this->records->recordsByBlock('K'),
        );
    }

    #[Test]
    public function recordsByBlockIsolatesBlocks(): void
    {
        $this->records->upsert('N', 'nick::pass', 'sha256:abc');
        $this->records->upsert('S', 'nickserv', 'NickServ!NickServ@services');
        $this->flushAndClear();

        self::assertSame(['nick::pass' => 'sha256:abc'], $this->records->recordsByBlock('N'));
        self::assertSame(['nickserv' => 'NickServ!NickServ@services'], $this->records->recordsByBlock('S'));
        self::assertSame([], $this->records->recordsByBlock('L'));
    }

    #[Test]
    public function deleteCascadeRemovesTheRecordAndAllDescendants(): void
    {
        $this->records->upsert('N', 'DavidLig::vhost', 'v');
        $this->records->upsert('N', 'davidlig::pass', 'p');
        $this->records->upsert('N', 'davidlig::pass::deep', 'd');
        $this->records->upsert('N', 'other::vhost', 'v');
        $this->records->upsert('C', '#chan::founder', 'DavidLig');
        $this->flushAndClear();

        self::assertTrue($this->records->deleteCascade('N', 'DAVIDLIG'));
        $this->flushAndClear();

        self::assertSame(['other::vhost' => 'v'], $this->records->recordsByBlock('N'));
        self::assertSame(['#chan::founder' => 'DavidLig'], $this->records->recordsByBlock('C'));
    }

    #[Test]
    public function deleteCascadeOfUnknownPathsIsANoOp(): void
    {
        $this->records->upsert('N', 'nick::vhost', 'v');
        $this->flushAndClear();

        self::assertFalse($this->records->deleteCascade('N', 'missing'));
        $this->flushAndClear();

        self::assertSame(['nick::vhost' => 'v'], $this->records->recordsByBlock('N'));
    }

    #[Test]
    public function replaceBlockReplacesTheBlockWithoutTouchingOtherBlocks(): void
    {
        $this->records->upsert('N', 'old::vhost', 'stale.example.net');
        $this->records->upsert('N', 'old::pass', 'stale-hash');
        $this->records->upsert('S', 'propagator', 'hub1.example');
        $this->flushAndClear();

        $this->records->replaceBlock('N', ['fresh::vhost' => 'new.example.net']);
        $this->flushAndClear();

        self::assertSame(['fresh::vhost' => 'new.example.net'], $this->records->recordsByBlock('N'));
        self::assertSame(['propagator' => 'hub1.example'], $this->records->recordsByBlock('S'));
    }

    #[Test]
    public function seedBlockMergesWithoutLosingExistingRecords(): void
    {
        $this->records->upsert('N', 'nick::vhost', 'raw-value');
        $this->flushAndClear();

        $this->records->seedBlock('N', ['nick::pass' => 'sha256:abc', 'nick::vhost' => 'seed-value']);
        $this->flushAndClear();

        self::assertSame([
            'nick::vhost' => 'seed-value',
            'nick::pass' => 'sha256:abc',
        ], $this->records->recordsByBlock('N'));
    }

    #[Test]
    public function seedBlockIsCaseInsensitiveOnIdentities(): void
    {
        $this->records->upsert('N', 'Nick::Pass', 'old');
        $this->flushAndClear();

        $this->records->seedBlock('N', ['nick::pass' => 'new']);
        $this->flushAndClear();

        self::assertCount(1, $this->records->recordsByBlock('N'));
        self::assertSame(['Nick::Pass' => 'new'], $this->records->recordsByBlock('N'));
    }

    #[Test]
    public function seedBlockPreservesDistinctKFilterPatterns(): void
    {
        $this->records->upsert('K', 'F::b64:QWJj::reason', 'old');
        $this->flushAndClear();

        $this->records->seedBlock('K', [
            'f::b64:QWJj::REASON' => 'updated',
            'F::b64:qwjj::reason' => 'distinct',
        ]);
        $this->flushAndClear();

        self::assertSame([
            'F::b64:QWJj::reason' => 'updated',
            'F::b64:qwjj::reason' => 'distinct',
        ], $this->records->recordsByBlock('K'));
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
        $this->records->upsert('I', '1.2.3.4::clones', '*5');
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
}
