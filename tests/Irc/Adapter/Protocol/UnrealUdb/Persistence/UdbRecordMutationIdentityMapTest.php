<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb\Persistence;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbAuthorityState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordMutationDoctrineStore;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/**
 * Live UDB mutations share the daemon EntityManager with other contexts; they
 * must release only the UDB entities they own.
 */
#[CoversClass(UdbRecordMutationDoctrineStore::class)]
#[Group('integration')]
final class UdbRecordMutationIdentityMapTest extends DoctrineIntegrationTestCase
{
    private UdbRecordMutationDoctrineStore $mutations;

    private UdbStreamOrigin $origin;

    private RegisteredNick $unrelated;

    #[Test]
    public function everyMutationKeepsUnrelatedManagedEntitiesAndReleasesOwnedOnes(): void
    {
        $this->mutations->beginLocalStream($this->origin);
        $this->assertOnlyUnrelatedEntitiesRemainManaged();

        self::assertSame('1', (string) $this->mutations->upsertWithManifest('N', 'alice::vhost', UdbValue::string('vhost.example'), $this->origin));
        $this->assertOnlyUnrelatedEntitiesRemainManaged();

        self::assertNull($this->mutations->upsertWithManifest('N', 'alice::vhost', UdbValue::string('vhost.example'), $this->origin));
        $this->assertOnlyUnrelatedEntitiesRemainManaged();

        self::assertSame('2', (string) $this->mutations->deleteCascadeWithManifest('N', 'alice::vhost', $this->origin));
        $this->assertOnlyUnrelatedEntitiesRemainManaged();

        self::assertSame('3', (string) $this->mutations->allocateBlockDropSequence('N', $this->origin));
        $this->assertOnlyUnrelatedEntitiesRemainManaged();
    }

    /**
     * The rejected mutation flushed the unrelated pending change inside the rolled-back
     * transaction; keeping that entity managed would leave it clean with a value the database
     * no longer holds, so the failure path discards the shared unit of work.
     */
    #[Test]
    public function aRejectedMutationRollsBackAndDiscardsEntitiesFlushedInsideTheRolledBackTransaction(): void
    {
        $this->mutations->beginLocalStream($this->origin);
        $this->unrelated->changeLanguage('es');

        try {
            $this->mutations->upsertWithManifest('Z', 'alice::vhost', UdbValue::string('vhost.example'), $this->origin);
            self::fail('An unknown block must be rejected.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('The UDB mutation would create an invalid block aggregate.', $exception->getMessage());
        }

        self::assertFalse($this->entityManager->contains($this->unrelated));
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap());
        self::assertCount(0, $this->entityManager->getConnection()->fetchFirstColumn('SELECT 1 FROM udb_records'));
        self::assertSame(['en'], $this->entityManager->getConnection()->fetchFirstColumn('SELECT language FROM registered_nicks'));
        $reloaded = $this->entityManager->find(RegisteredNick::class, $this->unrelated->getId());
        self::assertInstanceOf(RegisteredNick::class, $reloaded);
        self::assertSame('en', $reloaded->getLanguage());
    }

    #[Test]
    public function aNoOpMutationReleasesOwnedPendingInsertsButKeepsUnrelatedOnes(): void
    {
        $this->mutations->beginLocalStream($this->origin);
        self::assertSame('1', (string) $this->mutations->upsertWithManifest('N', 'alice::vhost', UdbValue::string('vhost.example'), $this->origin));
        $pendingOwned = new UdbBlockState('C');
        $pendingUnrelated = RegisteredNick::createActive('Bob', 'hash', null, 'en', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->entityManager->persist($pendingOwned);
        $this->entityManager->persist($pendingUnrelated);

        self::assertNull($this->mutations->upsertWithManifest('N', 'alice::vhost', UdbValue::string('vhost.example'), $this->origin));

        self::assertFalse($this->entityManager->contains($pendingOwned));
        self::assertTrue($this->entityManager->contains($pendingUnrelated));
        $this->assertOnlyUnrelatedEntitiesRemainManaged();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->mutations = new UdbRecordMutationDoctrineStore($this->entityManager, new MockClock('2026-09-13 16:30:00 UTC'));
        $this->origin = new UdbStreamOrigin('002', '0123456789abcdef');
        $this->unrelated = RegisteredNick::createActive('Alice', 'hash', null, 'en', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->entityManager->persist($this->unrelated);
        $this->entityManager->flush();
    }

    private function assertOnlyUnrelatedEntitiesRemainManaged(): void
    {
        self::assertTrue($this->entityManager->contains($this->unrelated));
        $identityMap = $this->entityManager->getUnitOfWork()->getIdentityMap();
        foreach ([UdbRecord::class, UdbBlockState::class, UdbAuthorityState::class] as $owned) {
            self::assertSame([], $identityMap[$owned] ?? [], $owned);
        }
    }
}
