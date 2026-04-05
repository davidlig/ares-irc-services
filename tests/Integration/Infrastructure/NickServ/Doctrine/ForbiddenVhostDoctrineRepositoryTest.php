<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\NickServ\Doctrine;

use App\Domain\NickServ\Entity\ForbiddenVhost;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use App\Infrastructure\NickServ\Doctrine\ForbiddenVhostDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ForbiddenVhostDoctrineRepository::class)]
#[Group('integration')]
final class ForbiddenVhostDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private ForbiddenVhostRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ForbiddenVhostDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsForbiddenVhost(): void
    {
        $forbidden = ForbiddenVhost::create('*.badhost.com', 1);

        $this->repository->save($forbidden);
        $this->flushAndClear();

        $found = $this->repository->findById($forbidden->getId());

        self::assertNotNull($found);
        self::assertSame('*.badhost.com', $found->getPattern());
        self::assertSame(1, $found->getCreatedByNickId());
    }

    #[Test]
    public function saveWithNullCreatedByNickId(): void
    {
        $forbidden = ForbiddenVhost::create('*.orphan.com', null);

        $this->repository->save($forbidden);
        $this->flushAndClear();

        $found = $this->repository->findById($forbidden->getId());

        self::assertNotNull($found);
        self::assertSame('*.orphan.com', $found->getPattern());
        self::assertNull($found->getCreatedByNickId());
    }

    #[Test]
    public function removeDeletesForbiddenVhost(): void
    {
        $forbidden = ForbiddenVhost::create('*.tobedeleted.com', 1);
        $this->repository->save($forbidden);
        $this->entityManager->flush();
        $id = $forbidden->getId();
        $this->entityManager->clear();

        $toDelete = $this->repository->findById($id);
        self::assertNotNull($toDelete);
        $this->repository->remove($toDelete);
        $this->flushAndClear();

        self::assertNull($this->repository->findById($id));
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById(999999));
    }

    #[Test]
    public function findByPatternReturnsMatchingForbiddenVhost(): void
    {
        $forbidden = ForbiddenVhost::create('*.example.com', 1);
        $this->repository->save($forbidden);
        $this->flushAndClear();

        $found = $this->repository->findByPattern('*.example.com');

        self::assertNotNull($found);
        self::assertSame('*.example.com', $found->getPattern());
    }

    #[Test]
    public function findByPatternReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByPattern('*.nonexistent.com'));
    }

    #[Test]
    public function findByPatternIsExactMatch(): void
    {
        $forbidden = ForbiddenVhost::create('*.example.com', 1);
        $this->repository->save($forbidden);
        $this->flushAndClear();

        self::assertNull($this->repository->findByPattern('example.com'));
        self::assertNull($this->repository->findByPattern('test.example.com'));
        self::assertNotNull($this->repository->findByPattern('*.example.com'));
    }

    #[Test]
    public function findAllReturnsAllForbiddenVhosts(): void
    {
        $forbidden1 = ForbiddenVhost::create('*.first.com', 1);
        $forbidden2 = ForbiddenVhost::create('*.second.com', 2);
        $forbidden3 = ForbiddenVhost::create('*.third.com', 3);

        $this->repository->save($forbidden1);
        $this->repository->save($forbidden2);
        $this->repository->save($forbidden3);
        $this->flushAndClear();

        $all = $this->repository->findAll();

        self::assertCount(3, $all);
        $patterns = array_map(static fn ($f) => $f->getPattern(), $all);
        self::assertContains('*.first.com', $patterns);
        self::assertContains('*.second.com', $patterns);
        self::assertContains('*.third.com', $patterns);
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findAll());
    }

    #[Test]
    public function countAllReturnsCorrectCount(): void
    {
        self::assertSame(0, $this->repository->countAll());

        $this->repository->save(ForbiddenVhost::create('*.first.com', 1));
        $this->repository->save(ForbiddenVhost::create('*.second.com', 2));
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countAll());
    }

    #[Test]
    public function clearCreatedByNickIdSetsNullForMatchingNick(): void
    {
        $forbidden1 = ForbiddenVhost::create('*.first.com', 1);
        $forbidden2 = ForbiddenVhost::create('*.second.com', 2);
        $forbidden3 = ForbiddenVhost::create('*.third.com', 1);

        $this->repository->save($forbidden1);
        $this->repository->save($forbidden2);
        $this->repository->save($forbidden3);
        $this->flushAndClear();

        $this->repository->clearCreatedByNickId(1);
        $this->flushAndClear();

        self::assertNull($this->repository->findById($forbidden1->getId())?->getCreatedByNickId());
        self::assertSame(2, $this->repository->findById($forbidden2->getId())?->getCreatedByNickId());
        self::assertNull($this->repository->findById($forbidden3->getId())?->getCreatedByNickId());
    }

    #[Test]
    public function clearCreatedByNickIdDoesNothingWhenNoMatch(): void
    {
        $forbidden = ForbiddenVhost::create('*.example.com', 1);
        $this->repository->save($forbidden);
        $this->flushAndClear();

        $this->repository->clearCreatedByNickId(999);
        $this->flushAndClear();

        self::assertSame(1, $this->repository->findById($forbidden->getId())?->getCreatedByNickId());
    }

    #[Test]
    public function patternIsUnique(): void
    {
        $forbidden1 = ForbiddenVhost::create('*.unique.com', 1);
        $this->repository->save($forbidden1);
        $this->flushAndClear();

        $forbidden2 = ForbiddenVhost::create('*.unique.com', 2);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->repository->save($forbidden2);
        $this->entityManager->flush();
    }
}
