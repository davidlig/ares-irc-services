<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbAuthorityStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbBlockStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbAuthorityStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbBlockStateRepositoryInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbRecordRepositoryInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

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
        $this->records->upsert('N', 'DavidLig::vhost', 'first.example.net');
        $this->records->upsert('N', 'davidlig::VHOST', 'second.example.net');
        $this->flushAndClear();

        self::assertSame(['DavidLig::vhost' => 'second.example.net'], $this->records->recordsByBlock('N'));
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

        $this->records->deleteCascade('N', 'DAVIDLIG');
        $this->flushAndClear();

        self::assertSame(['other::vhost' => 'v'], $this->records->recordsByBlock('N'));
        self::assertSame(['#chan::founder' => 'DavidLig'], $this->records->recordsByBlock('C'));
    }

    #[Test]
    public function deleteCascadeOfUnknownPathsIsANoOp(): void
    {
        $this->records->upsert('N', 'nick::vhost', 'v');
        $this->flushAndClear();

        $this->records->deleteCascade('N', 'missing');
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
    public function blockStatesAreUpsertedPerBlock(): void
    {
        $this->states->upsert('S', 'AAAA1111');
        $this->states->upsert('S', 'BBBB2222');
        $this->states->upsert('L', '00000000');
        $this->flushAndClear();

        $all = $this->states->all();
        self::assertCount(2, $all);
        self::assertSame('BBBB2222', $all['S']->getChecksum());
        self::assertSame('00000000', $all['L']->getChecksum());
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

        $this->authority->revoke();
        self::assertFalse($this->authority->isApproved());
    }
}
