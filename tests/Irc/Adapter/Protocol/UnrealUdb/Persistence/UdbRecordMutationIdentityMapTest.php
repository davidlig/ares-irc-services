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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use UnexpectedValueException;

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

    #[Test]
    public function aRejectedMutationRollsBackAndStillKeepsUnrelatedManagedEntities(): void
    {
        $this->mutations->beginLocalStream($this->origin);

        try {
            $this->mutations->upsertWithManifest('Z', 'alice::vhost', UdbValue::string('vhost.example'), $this->origin);
            self::fail('An unknown block must be rejected.');
        } catch (UnexpectedValueException $exception) {
            self::assertSame('The UDB mutation would create an invalid block aggregate.', $exception->getMessage());
        }

        $this->assertOnlyUnrelatedEntitiesRemainManaged();
        self::assertCount(0, $this->entityManager->getConnection()->fetchFirstColumn('SELECT 1 FROM udb_records'));
    }

    #[Test]
    public function aNoOpMutationReleasesOwnedPendingInsertsButKeepsUnrelatedOnes(): void
    {
        $this->mutations->beginLocalStream($this->origin);
        self::assertSame('1', (string) $this->mutations->upsertWithManifest('N', 'alice::vhost', UdbValue::string('vhost.example'), $this->origin));
        $pendingOwned = new UdbBlockState('C');
        $pendingUnrelated = RegisteredNick::createActive('Bob', 'hash', null, 'en', new DateTimeImmutable('2026-01-01 00:00:00'));
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
        $this->unrelated = RegisteredNick::createActive('Alice', 'hash', null, 'en', new DateTimeImmutable('2026-01-01 00:00:00'));
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
