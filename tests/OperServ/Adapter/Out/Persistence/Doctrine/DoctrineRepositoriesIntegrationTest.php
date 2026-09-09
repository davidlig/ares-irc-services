<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineGlineRepository;
use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineMotdRepository;
use App\OperServ\Application\Model\MessageDelivery;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class DoctrineRepositoriesIntegrationTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function glineAdapterRoundTripsItsApplicationEntry(): void
    {
        $repository = new DoctrineGlineRepository($this->entityManager);
        $createdAt = new DateTimeImmutable('2026-09-08T10:00:00+00:00');

        $repository->save('*@roundtrip.test', 42, 'abuse', $createdAt, null);
        $this->entityManager->clear();

        $entry = $repository->findByMask('*@roundtrip.test');
        self::assertNotNull($entry);
        self::assertNotNull($entry->id);
        self::assertSame(42, $entry->creatorAccountId);
        self::assertSame($createdAt->getTimestamp(), $entry->createdAt->getTimestamp());
    }

    #[Test]
    public function motdAdapterRoundTripsItsDoctrineOnlyRecord(): void
    {
        $repository = new DoctrineMotdRepository($this->entityManager);
        $createdAt = new DateTimeImmutable('2026-09-08T10:00:00+00:00');

        $created = $repository->add('Welcome', 'NickServ', MessageDelivery::Interactive, 42, $createdAt, null);
        $this->entityManager->clear();

        $entry = $repository->findById($created->id);
        self::assertNotNull($entry);
        self::assertSame(MessageDelivery::Interactive, $entry->delivery);
        self::assertSame(42, $entry->creatorAccountId);
        self::assertSame($createdAt->getTimestamp(), $entry->createdAt->getTimestamp());
    }
}
