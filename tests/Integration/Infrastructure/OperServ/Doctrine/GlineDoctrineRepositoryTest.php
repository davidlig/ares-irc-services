<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(\App\Infrastructure\OperServ\Doctrine\GlineDoctrineRepository::class)]
final class GlineDoctrineRepositoryTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;

    private ?GlineRepositoryInterface $repository = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(GlineRepositoryInterface::class);

        $this->em->createQuery('DELETE FROM App\Domain\OperServ\Entity\Gline g')->execute();
    }

    protected function tearDown(): void
    {
        if (null !== $this->em) {
            $this->em->createQuery('DELETE FROM App\Domain\OperServ\Entity\Gline g')->execute();
            $this->em->flush();
        }

        parent::tearDown();
    }

    #[Test]
    public function savePersistsGline(): void
    {
        $gline = Gline::create('*@test1234.com', 1, 'Test reason');

        $this->repository->save($gline);

        $this->em->clear();

        $found = $this->repository->findById($gline->getId());
        self::assertNotNull($found);
        self::assertSame('*@test1234.com', $found->getMask());
    }

    #[Test]
    public function removeDeletesGline(): void
    {
        $gline = Gline::create('*@deletetest.com', 1, 'To delete');
        $this->repository->save($gline);
        $id = $gline->getId();

        $this->repository->remove($gline);
        $this->em->clear();

        $found = $this->repository->findById($id);
        self::assertNull($found);
    }

    #[Test]
    public function findByIdReturnsNullForNonExistent(): void
    {
        $found = $this->repository->findById(999999);

        self::assertNull($found);
    }

    #[Test]
    public function findByMaskReturnsMatchingGline(): void
    {
        $gline = Gline::create('*@maskfindtest.com', 1, 'Test');
        $this->repository->save($gline);

        $found = $this->repository->findByMask('*@maskfindtest.com');

        self::assertNotNull($found);
        self::assertSame('*@maskfindtest.com', $found->getMask());
    }

    #[Test]
    public function findByMaskReturnsNullForNonExistent(): void
    {
        $found = $this->repository->findByMask('*@nonexistent.com');

        self::assertNull($found);
    }

    #[Test]
    public function findAllReturnsAllEntries(): void
    {
        $gline1 = Gline::create('*@alltest1.com', 1, 'First');
        $this->repository->save($gline1);
        $gline2 = Gline::create('*@alltest2.com', 1, 'Second');
        $this->repository->save($gline2);

        $all = $this->repository->findAll();

        self::assertCount(2, $all);
        $masks = array_map(static fn (Gline $g) => $g->getMask(), $all);
        self::assertContains('*@alltest1.com', $masks);
        self::assertContains('*@alltest2.com', $masks);
    }

    #[Test]
    public function findByMaskPatternReturnsMatching(): void
    {
        $gline1 = Gline::create('*@pattern1test.com', 1, 'One');
        $gline2 = Gline::create('*@pattern2test.com', 1, 'Two');
        $gline3 = Gline::create('*@other.com', 1, 'Other');
        $this->repository->save($gline1);
        $this->repository->save($gline2);
        $this->repository->save($gline3);

        $found = $this->repository->findByMaskPattern('pattern');

        self::assertCount(2, $found);
    }

    #[Test]
    public function findExpiredReturnsOnlyExpired(): void
    {
        $expired = Gline::create('*@expiredtest.com', 1, 'Expired', new DateTimeImmutable('-1 second'));
        $active = Gline::create('*@activetest.com', 1, 'Active', new DateTimeImmutable('+1 hour'));
        $this->repository->save($expired);
        $this->repository->save($active);

        $found = $this->repository->findExpired();

        self::assertCount(1, $found);
        self::assertSame('*@expiredtest.com', $found[0]->getMask());
    }

    #[Test]
    public function findActiveReturnsNonExpired(): void
    {
        $expired = Gline::create('*@expired2test.com', 1, 'Expired', new DateTimeImmutable('-1 second'));
        $active = Gline::create('*@active2test.com', 1, 'Active', new DateTimeImmutable('+1 hour'));
        $permanent = Gline::create('*@permanenttest.com', 1, 'Permanent');
        $this->repository->save($expired);
        $this->repository->save($active);
        $this->repository->save($permanent);

        $found = $this->repository->findActive();

        self::assertCount(2, $found);
        $masks = array_map(static fn (Gline $g) => $g->getMask(), $found);
        self::assertContains('*@active2test.com', $masks);
        self::assertContains('*@permanenttest.com', $masks);
    }

    #[Test]
    public function countAllReturnsCorrectCount(): void
    {
        $this->repository->save(Gline::create('*@count1test.com', 1, '1'));
        $this->repository->save(Gline::create('*@count2test.com', 1, '2'));

        $count = $this->repository->countAll();

        self::assertSame(2, $count);
    }

    #[Test]
    public function clearCreatorNickIdUpdatesToNull(): void
    {
        $gline = Gline::create('*@clearcreatortest.com', 42, 'Test');
        $this->repository->save($gline);
        $id = $gline->getId();

        $this->repository->clearCreatorNickId(42);
        $this->em->clear();

        $found = $this->repository->findById($id);
        self::assertNotNull($found);
        self::assertNull($found->getCreatorNickId());
    }

    #[Test]
    public function clearCreatorNickIdDoesNotAffectOtherNicks(): void
    {
        $gline1 = Gline::create('*@clear1test.com', 42, 'Test');
        $gline2 = Gline::create('*@clear2test.com', 99, 'Test');
        $this->repository->save($gline1);
        $this->repository->save($gline2);

        $this->repository->clearCreatorNickId(42);
        $this->em->clear();

        $found1 = $this->repository->findByMask('*@clear1test.com');
        $found2 = $this->repository->findByMask('*@clear2test.com');

        self::assertNull($found1?->getCreatorNickId());
        self::assertSame(99, $found2?->getCreatorNickId());
    }
}
