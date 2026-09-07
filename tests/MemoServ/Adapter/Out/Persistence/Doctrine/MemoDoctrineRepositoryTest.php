<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\Persistence\Doctrine;

use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoDoctrineRepository;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Domain\Entity\Memo;
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
        $memo = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Hello!', createdAt: new DateTimeImmutable());

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
        $memo = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Channel memo', createdAt: new DateTimeImmutable());

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
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Unread 1', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Unread 2', createdAt: new DateTimeImmutable());
        $memo3 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Read', createdAt: new DateTimeImmutable());
        $memo3->markAsRead(new DateTimeImmutable());

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->repository->save($memo3);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countUnreadByTargetNick(1));
    }

    #[Test]
    public function countUnreadByTargetChannelReturnsCorrectCount(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Unread', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Read', createdAt: new DateTimeImmutable());
        $memo2->markAsRead(new DateTimeImmutable());

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame(1, $this->repository->countUnreadByTargetChannel(10));
    }

    #[Test]
    public function countByTargetNickReturnsTotalCount(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'One', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Two', createdAt: new DateTimeImmutable());

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByTargetNick(1));
        self::assertSame(0, $this->repository->countByTargetNick(999));
    }

    #[Test]
    public function countByTargetChannelReturnsTotalCount(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'One', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Two', createdAt: new DateTimeImmutable());

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByTargetChannel(10));
        self::assertSame(0, $this->repository->countByTargetChannel(999));
    }

    #[Test]
    public function findByTargetNickAndIndexReturnsCorrectMemo(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'First', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Second', createdAt: new DateTimeImmutable());
        $memo3 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Third', createdAt: new DateTimeImmutable());

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
        $memo = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'Only one', createdAt: new DateTimeImmutable());
        $this->repository->save($memo);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetNickAndIndex(1, 0));
        self::assertNull($this->repository->findByTargetNickAndIndex(1, 2));
    }

    #[Test]
    public function findByTargetChannelAndIndexReturnsCorrectMemo(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'First', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Second', createdAt: new DateTimeImmutable());

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->flushAndClear();

        self::assertSame('First', $this->repository->findByTargetChannelAndIndex(10, 1)?->getMessage());
        self::assertSame('Second', $this->repository->findByTargetChannelAndIndex(10, 2)?->getMessage());
    }

    #[Test]
    public function findByTargetChannelAndIndexReturnsNullWhenIndexOutOfRange(): void
    {
        $memo = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'Only one', createdAt: new DateTimeImmutable());
        $this->repository->save($memo);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetChannelAndIndex(10, 0));
        self::assertNull($this->repository->findByTargetChannelAndIndex(10, 2));
        self::assertNull($this->repository->findByTargetChannelAndIndex(999, 1));
    }

    #[Test]
    public function deleteRemovesMemo(): void
    {
        $memo = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'To delete', createdAt: new DateTimeImmutable());
        $this->repository->save($memo);
        $this->entityManager->flush();

        $this->repository->delete($memo);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByTargetNick(1));
    }

    #[Test]
    public function deleteAllForNickRemovesAllMemos(): void
    {
        $memo1 = new Memo(targetNickId: 1, targetChannelId: null, senderNickId: 2, message: 'To nick', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: 2, targetChannelId: null, senderNickId: 1, message: 'From nick', createdAt: new DateTimeImmutable());
        $memo3 = new Memo(targetNickId: 3, targetChannelId: null, senderNickId: 4, message: 'Other', createdAt: new DateTimeImmutable());
        $memo4 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'From nick to channel', createdAt: new DateTimeImmutable());
        $memo5 = new Memo(targetNickId: null, targetChannelId: 20, senderNickId: 4, message: 'Other channel memo', createdAt: new DateTimeImmutable());

        $this->repository->save($memo1);
        $this->repository->save($memo2);
        $this->repository->save($memo3);
        $this->repository->save($memo4);
        $this->repository->save($memo5);
        $this->flushAndClear();

        $this->repository->deleteAllForNick(1);
        $this->flushAndClear();

        self::assertSame([], $this->repository->findByTargetNick(1));
        self::assertSame([], $this->repository->findByTargetNick(2));
        self::assertCount(1, $this->repository->findByTargetNick(3));
        self::assertSame([], $this->repository->findByTargetChannel(10));
        self::assertCount(1, $this->repository->findByTargetChannel(20));
    }

    #[Test]
    public function deleteAllForChannelRemovesAllMemos(): void
    {
        $memo1 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 1, message: 'One', createdAt: new DateTimeImmutable());
        $memo2 = new Memo(targetNickId: null, targetChannelId: 10, senderNickId: 2, message: 'Two', createdAt: new DateTimeImmutable());
        $memo3 = new Memo(targetNickId: null, targetChannelId: 20, senderNickId: 1, message: 'Other', createdAt: new DateTimeImmutable());

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
