<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\NickServ\Doctrine;

use App\Domain\NickServ\Entity\NickHistory;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use App\Infrastructure\NickServ\Doctrine\NickHistoryDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(NickHistoryDoctrineRepository::class)]
#[Group('integration')]
final class NickHistoryDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private NickHistoryRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new NickHistoryDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsHistory(): void
    {
        $history = $this->createHistory(1, 'SET_PASSWORD', 'OPER', 10, 'Password changed');

        $this->repository->save($history);
        $this->flushAndClear();

        $found = $this->repository->findById($history->getId());

        self::assertNotNull($found);
        self::assertSame(1, $found->getNickId());
        self::assertSame('SET_PASSWORD', $found->getAction());
        self::assertSame('OPER', $found->getPerformedBy());
        self::assertSame(10, $found->getPerformedByNickId());
        self::assertSame('Password changed', $found->getMessage());
    }

    #[Test]
    public function saveWithNullPerformedByNickId(): void
    {
        $history = $this->createHistory(1, 'DROP', 'Admin', null, 'Manual drop');

        $this->repository->save($history);
        $this->flushAndClear();

        $found = $this->repository->findById($history->getId());

        self::assertNotNull($found);
        self::assertNull($found->getPerformedByNickId());
    }

    #[Test]
    public function saveWithExtraData(): void
    {
        $history = NickHistory::record(
            nickId: 1,
            action: 'SUSPEND',
            performedBy: 'OPER',
            performedByNickId: 10,
            message: 'Suspended',
            extraData: ['reason' => 'Spam', 'duration' => '7d']
        );

        $this->repository->save($history);
        $this->flushAndClear();

        $found = $this->repository->findById($history->getId());

        self::assertNotNull($found);
        self::assertSame(['reason' => 'Spam', 'duration' => '7d'], $found->getExtraData());
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById(999999));
    }

    #[Test]
    public function findByNickIdReturnsHistoryOrderedByPerformedAtDesc(): void
    {
        $history1 = $this->createHistory(1, 'SET_PASSWORD', 'User1', 1, 'First', new DateTimeImmutable('-2 hours'));
        $history2 = $this->createHistory(1, 'SET_EMAIL', 'User1', 1, 'Second', new DateTimeImmutable('-1 hour'));
        $history3 = $this->createHistory(1, 'SET_URL', 'User1', 1, 'Third', new DateTimeImmutable('now'));

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->repository->save($history3);
        $this->flushAndClear();

        $found = $this->repository->findByNickId(1);

        self::assertCount(3, $found);
        self::assertSame('SET_URL', $found[0]->getAction());
        self::assertSame('SET_EMAIL', $found[1]->getAction());
        self::assertSame('SET_PASSWORD', $found[2]->getAction());
    }

    #[Test]
    public function findByNickIdReturnsOnlyForSpecificNick(): void
    {
        $history1 = $this->createHistory(1, 'SET_PASSWORD', 'User1', 1, 'History 1');
        $history2 = $this->createHistory(2, 'SET_PASSWORD', 'User2', 2, 'History 2');

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->flushAndClear();

        $found = $this->repository->findByNickId(1);

        self::assertCount(1, $found);
        self::assertSame(1, $found[0]->getNickId());
    }

    #[Test]
    public function findByNickIdReturnsEmptyArrayWhenNotFound(): void
    {
        self::assertSame([], $this->repository->findByNickId(999999));
    }

    #[Test]
    public function findByNickIdWithLimit(): void
    {
        $history1 = $this->createHistory(1, 'ACTION1', 'User', 1, 'One');
        $history2 = $this->createHistory(1, 'ACTION2', 'User', 1, 'Two');
        $history3 = $this->createHistory(1, 'ACTION3', 'User', 1, 'Three');

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->repository->save($history3);
        $this->flushAndClear();

        $found = $this->repository->findByNickId(1, limit: 2);

        self::assertCount(2, $found);
    }

    #[Test]
    public function findByNickIdWithOffset(): void
    {
        $history1 = $this->createHistory(1, 'ACTION1', 'User', 1, 'One', new DateTimeImmutable('-3 hours'));
        $history2 = $this->createHistory(1, 'ACTION2', 'User', 1, 'Two', new DateTimeImmutable('-2 hours'));
        $history3 = $this->createHistory(1, 'ACTION3', 'User', 1, 'Three', new DateTimeImmutable('-1 hour'));

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->repository->save($history3);
        $this->flushAndClear();

        $found = $this->repository->findByNickId(1, offset: 1);

        self::assertCount(2, $found);
        self::assertSame('ACTION2', $found[0]->getAction());
    }

    #[Test]
    public function findByNickIdWithLimitAndOffset(): void
    {
        $history1 = $this->createHistory(1, 'ACTION1', 'User', 1, 'One', new DateTimeImmutable('-3 hours'));
        $history2 = $this->createHistory(1, 'ACTION2', 'User', 1, 'Two', new DateTimeImmutable('-2 hours'));
        $history3 = $this->createHistory(1, 'ACTION3', 'User', 1, 'Three', new DateTimeImmutable('-1 hour'));
        $history4 = $this->createHistory(1, 'ACTION4', 'User', 1, 'Four', new DateTimeImmutable('now'));

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->repository->save($history3);
        $this->repository->save($history4);
        $this->flushAndClear();

        $found = $this->repository->findByNickId(1, limit: 2, offset: 1);

        self::assertCount(2, $found);
        $actions = array_map(static fn ($h) => $h->getAction(), $found);
        self::assertContains('ACTION3', $actions);
        self::assertContains('ACTION2', $actions);
    }

    #[Test]
    public function countByNickIdReturnsCorrectCount(): void
    {
        self::assertSame(0, $this->repository->countByNickId(1));

        $history1 = $this->createHistory(1, 'ACTION1', 'User', 1, 'One');
        $history2 = $this->createHistory(1, 'ACTION2', 'User', 1, 'Two');
        $history3 = $this->createHistory(2, 'ACTION3', 'Other', 2, 'Three');

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->repository->save($history3);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByNickId(1));
        self::assertSame(1, $this->repository->countByNickId(2));
        self::assertSame(0, $this->repository->countByNickId(999));
    }

    #[Test]
    public function deleteByIdRemovesHistoryAndReturnsTrue(): void
    {
        $history = $this->createHistory(1, 'DELETE_ME', 'User', 1, 'To be deleted');
        $this->repository->save($history);
        $this->flushAndClear();

        $id = $history->getId();
        $result = $this->repository->deleteById($id);
        $this->flushAndClear();

        self::assertTrue($result);
        self::assertNull($this->repository->findById($id));
    }

    #[Test]
    public function deleteByIdReturnsFalseWhenNotFound(): void
    {
        $result = $this->repository->deleteById(999999);

        self::assertFalse($result);
    }

    #[Test]
    public function deleteByNickIdRemovesAllHistoryAndReturnsCount(): void
    {
        $history1 = $this->createHistory(1, 'ACTION1', 'User1', 1, 'One');
        $history2 = $this->createHistory(1, 'ACTION2', 'User1', 1, 'Two');
        $history3 = $this->createHistory(2, 'ACTION3', 'User2', 2, 'Three');

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->repository->save($history3);
        $this->flushAndClear();

        $count = $this->repository->deleteByNickId(1);
        $this->flushAndClear();

        self::assertSame(2, $count);
        self::assertSame([], $this->repository->findByNickId(1));
        self::assertCount(1, $this->repository->findByNickId(2));
    }

    #[Test]
    public function deleteByNickIdReturnsZeroWhenNoHistory(): void
    {
        $count = $this->repository->deleteByNickId(999999);

        self::assertSame(0, $count);
    }

    #[Test]
    public function deleteOlderThanRemovesOnlyOlderEntries(): void
    {
        $old1 = $this->createHistory(1, 'OLD1', 'User', 1, 'Old 1', new DateTimeImmutable('-10 days'));
        $old2 = $this->createHistory(1, 'OLD2', 'User', 1, 'Old 2', new DateTimeImmutable('-8 days'));
        $recent = $this->createHistory(1, 'RECENT', 'User', 1, 'Recent', new DateTimeImmutable('-1 day'));

        $this->repository->save($old1);
        $this->repository->save($old2);
        $this->repository->save($recent);
        $this->flushAndClear();

        $threshold = new DateTimeImmutable('-5 days');
        $count = $this->repository->deleteOlderThan($threshold);
        $this->flushAndClear();

        self::assertSame(2, $count);

        $allHistory = $this->repository->findByNickId(1);
        self::assertCount(1, $allHistory);
        self::assertSame('RECENT', $allHistory[0]->getAction());
    }

    #[Test]
    public function deleteOlderThanReturnsZeroWhenNoneOlder(): void
    {
        $history = $this->createHistory(1, 'RECENT', 'User', 1, 'Recent', new DateTimeImmutable('-1 day'));
        $this->repository->save($history);
        $this->flushAndClear();

        $threshold = new DateTimeImmutable('-30 days');
        $count = $this->repository->deleteOlderThan($threshold);

        self::assertSame(0, $count);
        self::assertCount(1, $this->repository->findByNickId(1));
    }

    #[Test]
    public function deleteOlderThanWithBoundaryExactly(): void
    {
        $threshold = new DateTimeImmutable('-5 days');
        $exactly5Days = $threshold->modify('+1 second');

        $older = $this->createHistory(1, 'OLDER', 'User', 1, 'Older', new DateTimeImmutable('-6 days'));
        $exact = $this->createHistory(1, 'EXACT', 'User', 1, 'Exact', $exactly5Days);

        $this->repository->save($older);
        $this->repository->save($exact);
        $this->flushAndClear();

        $count = $this->repository->deleteOlderThan($threshold);
        $this->flushAndClear();

        self::assertSame(1, $count);
        self::assertCount(1, $this->repository->findByNickId(1));
    }

    #[Test]
    public function historyForDifferentNicksAreIndependent(): void
    {
        $history1 = $this->createHistory(1, 'ACTION1', 'User1', 1, 'For nick 1');
        $history2 = $this->createHistory(2, 'ACTION2', 'User2', 2, 'For nick 2');

        $this->repository->save($history1);
        $this->repository->save($history2);
        $this->flushAndClear();

        $this->repository->deleteByNickId(1);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByNickId(1));
        self::assertCount(1, $this->repository->findByNickId(2));
    }

    private function createHistory(
        int $nickId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        string $message,
        ?DateTimeImmutable $performedAt = null
    ): NickHistory {
        return NickHistory::record(
            nickId: $nickId,
            action: $action,
            performedBy: $performedBy,
            performedByNickId: $performedByNickId,
            message: $message,
            performedAt: $performedAt
        );
    }
}
