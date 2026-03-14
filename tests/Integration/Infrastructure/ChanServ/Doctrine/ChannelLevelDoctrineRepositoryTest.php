<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\ChanServ\Doctrine;

use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Infrastructure\ChanServ\Doctrine\ChannelLevelDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ChannelLevelDoctrineRepository::class)]
#[Group('integration')]
final class ChannelLevelDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private ChannelLevelRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelLevelDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsLevel(): void
    {
        $level = new ChannelLevel(channelId: 1, levelKey: 'AUTOOP', value: 50);

        $this->repository->save($level);
        $this->flushAndClear();

        $found = $this->repository->findByChannelAndKey(1, 'AUTOOP');

        self::assertNotNull($found);
        self::assertSame(50, $found->getValue());
    }

    #[Test]
    public function findByChannelAndKeyReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByChannelAndKey(999, 'AUTOOP'));
    }

    #[Test]
    public function findByChannelAndKeyIsUnique(): void
    {
        $level1 = new ChannelLevel(channelId: 1, levelKey: 'AUTOOP', value: 50);
        $level2 = new ChannelLevel(channelId: 1, levelKey: 'AUTOVOICE', value: 10);

        $this->repository->save($level1);
        $this->repository->save($level2);
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByChannelAndKey(1, 'AUTOOP'));
        self::assertNotNull($this->repository->findByChannelAndKey(1, 'AUTOVOICE'));
        self::assertNull($this->repository->findByChannelAndKey(1, 'NONEXISTENT'));
    }

    #[Test]
    public function listByChannelReturnsLevelsOrderedByKey(): void
    {
        $level1 = new ChannelLevel(channelId: 1, levelKey: 'SET', value: 100);
        $level2 = new ChannelLevel(channelId: 1, levelKey: 'AUTOOP', value: 50);
        $level3 = new ChannelLevel(channelId: 1, levelKey: 'ACCESSLIST', value: 30);

        $this->repository->save($level1);
        $this->repository->save($level2);
        $this->repository->save($level3);
        $this->flushAndClear();

        $list = $this->repository->listByChannel(1);

        self::assertCount(3, $list);
        self::assertSame('ACCESSLIST', $list[0]->getLevelKey());
        self::assertSame('AUTOOP', $list[1]->getLevelKey());
        self::assertSame('SET', $list[2]->getLevelKey());
    }

    #[Test]
    public function listByChannelReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->listByChannel(999));
    }

    #[Test]
    public function listByChannelSeparatesChannels(): void
    {
        $level1 = new ChannelLevel(channelId: 1, levelKey: 'AUTOOP', value: 50);
        $level2 = new ChannelLevel(channelId: 2, levelKey: 'AUTOOP', value: 100);

        $this->repository->save($level1);
        $this->repository->save($level2);
        $this->flushAndClear();

        $list1 = $this->repository->listByChannel(1);
        $list2 = $this->repository->listByChannel(2);

        self::assertCount(1, $list1);
        self::assertSame(50, $list1[0]->getValue());

        self::assertCount(1, $list2);
        self::assertSame(100, $list2[0]->getValue());
    }

    #[Test]
    public function removeAllForChannelDeletesAllLevels(): void
    {
        $level1 = new ChannelLevel(channelId: 1, levelKey: 'AUTOOP', value: 50);
        $level2 = new ChannelLevel(channelId: 1, levelKey: 'AUTOVOICE', value: 10);
        $level3 = new ChannelLevel(channelId: 2, levelKey: 'AUTOOP', value: 100);

        $this->repository->save($level1);
        $this->repository->save($level2);
        $this->repository->save($level3);
        $this->flushAndClear();

        $this->repository->removeAllForChannel(1);
        $this->flushAndClear();

        self::assertSame([], $this->repository->listByChannel(1));
        self::assertCount(1, $this->repository->listByChannel(2));
    }
}
