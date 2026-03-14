<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\MemoServ\Doctrine;

use App\Domain\MemoServ\Entity\Memo;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Infrastructure\MemoServ\Doctrine\MemoDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MemoDoctrineRepository::class)]
#[Group('integration')]
final class MemoDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private MemoRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MemoDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsMemoToNick(): void
    {
        $memo = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Hello!');

        $this->repository->save($memo);
        $this->flushAndClear();

        $list = $this->repository->findByTargetNick(1);

        self::assertCount(1, $list);
        self::assertSame('Hello!', $list[0]->getMessage());
        self::assertSame(2, $list[0]->getSenderNickId());
    }

    #[Test]
    public function savePersistsMemoToChannel(): void
    {
        $memo = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Channel memo');

        $this->repository->save($memo);
        $this->flushAndClear();

        $list = $this->repository->findByTargetChannel(10);

        self::assertCount(1, $list);
        self::assertSame('Channel memo', $list[0]->getMessage());
    }

    #[Test]
    public function findByTargetNickReturnsOrderedByCreatedAt(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'First', createdAt: new DateTimeImmutable('-1 hour'));
        $memo2 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Second', createdAt: new DateTimeImmutable('-30 minutes'));

        $this->repository->save($memo2);
        $this->repository->save($memo1);
        $this->flushAndClear();

        $list = $this->repository->findByTargetNick(1);

        self::assertCount(2, $list);
        self::assertSame('First', $list[0]->getMessage());
        self::assertSame('Second', $list[1]->getMessage());
    }

    #[Test]
    public function findByTargetNickReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findByTargetNick(999));
    }

    #[Test]
    public function findByTargetChannelReturnsOrderedByCreatedAt(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'First', createdAt: new DateTimeImmutable('-1 hour'));
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Second', createdAt: new DateTimeImmutable('-30 minutes'));

        $this->repository->save($memo2);
        $this->repository->save($memo1);
        $this->flushAndClear();

        $list = $this->repository->findByTargetChannel(10);

        self::assertCount(2, $list);
        self::assertSame('First', $list[0]->getMessage());
        self::assertSame('Second', $list[1]->getMessage());
    }

    #[Test]
    public function countUnreadByTargetNickReturnsCorrectCount(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Unread 1');
        $memo2 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Unread 2');
        $memo3 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Read');
        $memo3->markAsRead();

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->repository->save($memo3);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countUnreadByTargetNick(1));
    }

    #[Test]
    public function countUnreadByTargetChannelReturnsCorrectCount(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Unread');
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Read');
        $memo2->markAsRead();

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame(1, $this->repository->countUnreadByTargetChannel(10));
    }

    #[Test]
    public function countByTargetNickReturnsTotalCount(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'One');
        $memo2 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Two');

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByTargetNick(1));
        self::assertSame(0, $this->repository->countByTargetNick(999));
    }

    #[Test]
    public function countByTargetChannelReturnsTotalCount(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'One');
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Two');

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByTargetChannel(10));
        self::assertSame(0, $this->repository->countByTargetChannel(999));
    }

    #[Test]
    public function findByIdReturnsMemo(): void
    {
        $memo = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Test');
        $this->repository->save($memo);
        $this->flushAndClear();

        $found = $this->repository->findById($memo->getId());

        self::assertNotNull($found);
        self::assertSame('Test', $found->getMessage());
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findById(999999));
    }

    #[Test]
    public function findByTargetNickAndIndexReturnsCorrectMemo(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'First');
        $memo2 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Second');
        $memo3 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Third');

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->repository->save($memo3);
        $this->flushAndClear();

        self::assertSame('First', $this->repository->findByTargetNickAndIndex(1, 1)?->getMessage());
        self::assertSame('Second', $this->repository->findByTargetNickAndIndex(1, 2)?->getMessage());
        self::assertSame('Third', $this->repository->findByTargetNickAndIndex(1, 3)?->getMessage());
    }

    #[Test]
    public function findByTargetNickAndIndexReturnsNullForInvalidIndex(): void
    {
        $memo = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Only one');
        $this->repository->save($memo);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetNickAndIndex(1, 0));
        self::assertNull($this->repository->findByTargetNickAndIndex(1, 2));
    }

    #[Test]
    public function findByTargetChannelAndIndexReturnsCorrectMemo(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'First');
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Second');

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame('First', $this->repository->findByTargetChannelAndIndex(10, 1)?->getMessage());
        self::assertSame('Second', $this->repository->findByTargetChannelAndIndex(10, 2)?->getMessage());
    }

    #[Test]
    public function deleteRemovesMemo(): void
    {
        $memo = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'To delete');
        $this->repository->save($memo);
        $this->entityManager->flush();

        $this->repository->delete($memo);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByTargetNick(1));
    }

    #[Test]
    public function deleteAllForNickRemovesAllMemos(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'To nick');
        $memo2 = new Memo(targetNickId: 2, targetChannelId: null, senderNickId: 1, message: 'From nick');
        $memo3 = new Memo(targetNickId: 3, targetChannelId: null, senderNickId: 4, message: 'Other');

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->repository->save($memo3);
        $this->flushAndClear();

        $this->repository->deleteAllForNick(1);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByTargetNick(1));
        self::assertSame([], $this->repository->findByTargetNick(2));
        self::assertCount(1, $this->repository->findByTargetNick(3));
    }

    #[Test]
    public function deleteAllForChannelRemovesAllMemos(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'One');
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 2, message: 'Two');
        $memo3 = new Memo(targetNickId: null, targetChannelId: 20, senderNickId: 1, message: 'Other');

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->repository->save($memo3);
        $this->flushAndClear();

        $this->repository->deleteAllForChannel(10);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByTargetChannel(10));
        self::assertCount(1, $this->repository->findByTargetChannel(20));
    }
}
