<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\BlockedEmailDomainDoctrineRepository;
use App\NickServ\Domain\Entity\BlockedEmailDomain;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(BlockedEmailDomainDoctrineRepository::class)]
#[Group('integration')]
final class BlockedEmailDomainDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private BlockedEmailDomainDoctrineRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new BlockedEmailDomainDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function startsWithAnEmptyList(): void
    {
        self::assertFalse($this->repository->isBlocked('example.com'));
        self::assertSame([], $this->repository->listAll());
    }

    #[Test]
    public function storesAndFindsABlockedDomain(): void
    {
        $this->repository->add($this->domain('example.com'));
        $this->flushAndClear();

        self::assertTrue($this->repository->isBlocked('example.com'));
        self::assertFalse($this->repository->isBlocked('other.com'));
    }

    #[Test]
    public function listsDomainsInAscendingOrder(): void
    {
        $this->repository->add($this->domain('zeta.com'));
        $this->repository->add($this->domain('alpha.com'));
        $this->repository->add($this->domain('middle.com'));
        $this->flushAndClear();

        self::assertSame(
            ['alpha.com', 'middle.com', 'zeta.com'],
            array_map(static fn (BlockedEmailDomain $domain): string => $domain->getDomain(), $this->repository->listAll()),
        );
    }

    #[Test]
    public function removesTheEntryAtTheGivenPosition(): void
    {
        $this->repository->add($this->domain('alpha.com'));
        $this->repository->add($this->domain('beta.com'));
        $this->flushAndClear();

        $removed = $this->repository->removeAtPosition(2);
        $this->flushAndClear();

        self::assertInstanceOf(BlockedEmailDomain::class, $removed);
        self::assertSame('beta.com', $removed->getDomain());
        self::assertSame(
            ['alpha.com'],
            array_map(static fn (BlockedEmailDomain $domain): string => $domain->getDomain(), $this->repository->listAll()),
        );
    }

    #[Test]
    public function removeAtPositionReturnsNullForOutOfRangePositions(): void
    {
        $this->repository->add($this->domain('alpha.com'));
        $this->flushAndClear();

        self::assertNull($this->repository->removeAtPosition(0));
        self::assertNull($this->repository->removeAtPosition(2));
        self::assertTrue($this->repository->isBlocked('alpha.com'));
    }

    #[Test]
    public function removeAllClearsTheListAndReportsTheCount(): void
    {
        $this->repository->add($this->domain('alpha.com'));
        $this->repository->add($this->domain('beta.com'));
        $this->flushAndClear();

        self::assertSame(2, $this->repository->removeAll());
        $this->flushAndClear();

        self::assertSame([], $this->repository->listAll());
        self::assertSame(0, $this->repository->removeAll());
    }

    private function domain(string $domain): BlockedEmailDomain
    {
        return new BlockedEmailDomain($domain, new DateTimeImmutable('2026-10-19 12:00:00 UTC'));
    }
}
