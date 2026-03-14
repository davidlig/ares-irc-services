<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\MemoServ\Doctrine;

use App\Domain\MemoServ\Entity\MemoIgnore;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Infrastructure\MemoServ\Doctrine\MemoIgnoreDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MemoIgnoreDoctrineRepository::class)]
#[Group('integration')]
final class MemoIgnoreDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private MemoIgnoreRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MemoIgnoreDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsIgnoreForNick(): void
    {
        $ignore = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 100);

        $this->repository->save($ignore);
        $this->flushAndClear();

        $found = $this->repository->findByTargetNickAndIgnored(1, 100);

        self::assertNotNull($found);
        self::assertSame(100, $found->getIgnoredNickId());
    }

    #[Test]
    public function savePersistsIgnoreForChannel(): void
    {
        $ignore = new MemoIgnore(targetNickId: null, targetChannelId: 10, ignoredNickId: 100);

        $this->repository->save($ignore);
        $this->flushAndClear();

        $found = $this->repository->findByTargetChannelAndIgnored(10, 100);

        self::assertNotNull($found);
        self::assertSame(100, $found->getIgnoredNickId());
    }

    #[Test]
    public function findByTargetNickAndIgnoredReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByTargetNickAndIgnored(999, 999));
    }

    #[Test]
    public function findByTargetChannelAndIgnoredReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByTargetChannelAndIgnored(999, 999));
    }

    #[Test]
    public function listByTargetNickReturnsIgnoresOrderedByNickId(): void
    {
        $ignore1 = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 300);
        $ignore2 = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 100);
        $ignore3 = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 200);

        $this->repository->save($ignore1);
        $this->repository->save($ignore2);
        $this->repository->save($ignore3);
        $this->flushAndClear();

        $list = $this->repository->listByTargetNick(1);

        self::assertCount(3, $list);
        self::assertSame(100, $list[0]->getIgnoredNickId());
        self::assertSame(200, $list[1]->getIgnoredNickId());
        self::assertSame(300, $list[2]->getIgnoredNickId());
    }

    #[Test]
    public function listByTargetChannelReturnsIgnoresOrderedByNickId(): void
    {
        $ignore1 = new MemoIgnore(targetNickId: null, targetChannelId: 10, ignoredNickId: 200);
        $ignore2 = new MemoIgnore(targetNickId: null, targetChannelId: 10, ignoredNickId: 100);

        $this->repository->save($ignore1);
        $this->repository->save($ignore2);
        $this->flushAndClear();

        $list = $this->repository->listByTargetChannel(10);

        self::assertCount(2, $list);
        self::assertSame(100, $list[0]->getIgnoredNickId());
        self::assertSame(200, $list[1]->getIgnoredNickId());
    }

    #[Test]
    public function listByTargetNickReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->listByTargetNick(999));
    }

    #[Test]
    public function countByTargetNickReturnsCorrectCount(): void
    {
        $ignore1 = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 100);
        $ignore2 = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 200);
        $ignore3 = new MemoIgnore(targetNickId: 2, targetChannelId: null, ignoredNickId: 100);

        $this->repository->save($ignore1);
        $this->repository->save($ignore2);
        $this->repository->save($ignore3);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByTargetNick(1));
        self::assertSame(1, $this->repository->countByTargetNick(2));
        self::assertSame(0, $this->repository->countByTargetNick(999));
    }

    #[Test]
    public function countByTargetChannelReturnsCorrectCount(): void
    {
        $ignore1 = new MemoIgnore(targetNickId: null, targetChannelId: 10, ignoredNickId: 100);
        $ignore2 = new MemoIgnore(targetNickId: null, targetChannelId: 20, ignoredNickId: 100);

        $this->repository->save($ignore1);
        $this->repository->save($ignore2);
        $this->flushAndClear();

        self::assertSame(1, $this->repository->countByTargetChannel(10));
        self::assertSame(0, $this->repository->countByTargetChannel(999));
    }

    #[Test]
    public function deleteRemovesIgnore(): void
    {
        $ignore = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 100);
        $this->repository->save($ignore);
        $this->entityManager->flush();

        $this->repository->delete($ignore);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetNickAndIgnored(1, 100));
    }

    #[Test]
    public function deleteAllForNickRemovesAsTargetAndIgnored(): void
    {
        $ignore1 = new MemoIgnore(targetNickId: 1, targetChannelId: null, ignoredNickId: 100);
        $ignore2 = new MemoIgnore(targetNickId: 2, targetChannelId: null, ignoredNickId: 1);
        $ignore3 = new MemoIgnore(targetNickId: 3, targetChannelId: null, ignoredNickId: 100);

        $this->repository->save($ignore1);
        $this->repository->save($ignore2);
        $this->repository->save($ignore3);
        $this->flushAndClear();

        $this->repository->deleteAllForNick(1);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetNickAndIgnored(1, 100));
        self::assertNull($this->repository->findByTargetNickAndIgnored(2, 1));
        self::assertCount(1, $this->repository->listByTargetNick(3));
    }

    #[Test]
    public function deleteAllForChannelRemovesAllIgnores(): void
    {
        $ignore1 = new MemoIgnore(targetNickId: null, targetChannelId: 10, ignoredNickId: 100);
        $ignore2 = new MemoIgnore(targetNickId: null, targetChannelId: 20, ignoredNickId: 100);

        $this->repository->save($ignore1);
        $this->repository->save($ignore2);
        $this->flushAndClear();

        $this->repository->deleteAllForChannel(10);
        $this->flushAndClear();

        self::assertSame([], $this->repository->listByTargetChannel(10));
        self::assertCount(1, $this->repository->listByTargetChannel(20));
    }
}
