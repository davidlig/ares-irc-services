<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Bootstrap\Adapter\Out\Persistence\DoctrineTransactionManager;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChanDoctrineTransactionBoundary;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelHistoryDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\UseCase\ManageHistory\ChannelHistoryAction;
use App\ChanServ\Application\UseCase\ManageHistory\ManageChannelHistory;
use App\ChanServ\Application\UseCase\ManageHistory\ManageChannelHistoryHandler;
use App\ChanServ\Application\UseCase\ManageHistory\ManageChannelHistoryOutcome;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[CoversClass(ManageChannelHistoryHandler::class)]
#[CoversClass(ChannelHistoryDoctrineRepository::class)]
#[Group('integration')]
final class ChannelHistoryClearTransactionTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function clearDeletesPriorEntriesAndLeavesActorEntryWithoutInflatingCount(): void
    {
        $repository = new ChannelHistoryDoctrineRepository($this->entityManager);
        $channel = $this->persistChannel();
        $repository->save($this->entry($channel->getId(), 'FIRST'));
        $repository->save($this->entry($channel->getId(), 'SECOND'));
        $handler = $this->handler($repository, $repository);

        $result = $handler->handle($this->clearCommand());

        self::assertSame(ManageChannelHistoryOutcome::Cleared, $result->outcome);
        self::assertSame(2, $result->total);
        $entries = $repository->findByChannelId($channel->getId());
        self::assertCount(1, $entries);
        self::assertSame('HISTORY_CLEAR', $entries[0]->getAction());
        self::assertSame('Founder', $entries[0]->getPerformedBy());
        self::assertNull($entries[0]->getPerformedByNickId());
        self::assertSame([], $entries[0]->getExtraData());
    }

    #[Test]
    public function clearOnInitiallyEmptyHistoryStillLeavesActorEntry(): void
    {
        $repository = new ChannelHistoryDoctrineRepository($this->entityManager);
        $channel = $this->persistChannel();

        $result = $this->handler($repository, $repository)->handle($this->clearCommand());

        self::assertSame(0, $result->total);
        $entries = $repository->findByChannelId($channel->getId());
        self::assertCount(1, $entries);
        self::assertSame('HISTORY_CLEAR', $entries[0]->getAction());
    }

    #[Test]
    public function replacementInsertFailureRollsBackDeletionAndPreservesPriorEntries(): void
    {
        $repository = new ChannelHistoryDoctrineRepository($this->entityManager);
        $channel = $this->persistChannel();
        $repository->save($this->entry($channel->getId(), 'FIRST'));
        $repository->save($this->entry($channel->getId(), 'SECOND'));
        $failingRepository = new class($repository) implements ChannelHistoryRepositoryInterface {
            public function __construct(private readonly ChannelHistoryRepositoryInterface $inner) {}

            public function save(ChannelHistory $history): void
            {
                if ('HISTORY_CLEAR' === $history->getAction()) {
                    throw new RuntimeException('replacement insertion failed');
                }
                $this->inner->save($history);
            }

            public function findById(int $id): ?ChannelHistory
            {
                return $this->inner->findById($id);
            }

            public function findByChannelId(int $channelId, ?int $limit = null, int $offset = 0): array
            {
                return $this->inner->findByChannelId($channelId, $limit, $offset);
            }

            public function countByChannelId(int $channelId): int
            {
                return $this->inner->countByChannelId($channelId);
            }

            public function deleteById(int $id): bool
            {
                return $this->inner->deleteById($id);
            }

            public function deleteByChannelId(int $channelId): int
            {
                return $this->inner->deleteByChannelId($channelId);
            }

            public function deleteOlderThan(DateTimeImmutable $threshold): int
            {
                return $this->inner->deleteOlderThan($threshold);
            }
        };

        try {
            $this->handler($repository, $failingRepository)->handle($this->clearCommand());
            self::fail('Expected replacement history insertion to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('replacement insertion failed', $exception->getMessage());
        }

        self::assertSame(2, $repository->countByChannelId($channel->getId()));
        self::assertSame(['SECOND', 'FIRST'], array_map(
            static fn (ChannelHistory $entry): string => $entry->getAction(),
            $repository->findByChannelId($channel->getId()),
        ));
    }

    private function handler(ChannelHistoryRepositoryInterface $repository, ChannelHistoryRepositoryInterface $writeRepository): ManageChannelHistoryHandler
    {
        return new ManageChannelHistoryHandler(
            new RegisteredChannelDoctrineRepository($this->entityManager),
            $repository,
            new ChannelHistoryService($writeRepository),
            $this->createStub(ChanUserAccountPort::class),
            new ChanDoctrineTransactionBoundary(new DoctrineTransactionManager($this->entityManager)),
        );
    }

    private function persistChannel(): RegisteredChannel
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable('2026-01-01'), '#history', 7, 'Test');
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        return $channel;
    }

    private function clearCommand(): ManageChannelHistory
    {
        return new ManageChannelHistory(
            '#history',
            ChannelHistoryAction::Clear,
            'Founder',
            7,
            'ignored-ip',
            'ignored-host',
            new DateTimeImmutable('2026-02-01'),
        );
    }

    private function entry(int $channelId, string $action): ChannelHistory
    {
        return ChannelHistory::record(
            channelId: $channelId,
            action: $action,
            performedBy: 'Previous',
            performedByNickId: 7,
            performedAt: new DateTimeImmutable('SECOND' === $action ? '2026-01-11' : '2026-01-10'),
            message: 'previous entry',
        );
    }
}
