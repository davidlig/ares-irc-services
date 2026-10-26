<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Bootstrap\Adapter\Out\Persistence\DoctrineTransactionManager;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickDoctrineTransactionBoundary;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickHistoryDoctrineRepository;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;
use App\NickServ\Application\UseCase\History\HistoryNick;
use App\NickServ\Application\UseCase\History\HistoryNickAction;
use App\NickServ\Application\UseCase\History\HistoryNickHandler;
use App\NickServ\Domain\Entity\NickHistory;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(HistoryNickHandler::class)]
#[CoversClass(NickHistoryService::class)]
#[CoversClass(NickHistoryDoctrineRepository::class)]
#[CoversClass(DoctrineTransactionManager::class)]
final class NickHistoryClearTransactionTest extends DoctrineIntegrationTestCase
{
    public function testReplacementFailureRollsBackHistoryDeletion(): void
    {
        $repository = new NickHistoryDoctrineRepository($this->entityManager);
        $repository->save(NickHistory::record(
            nickId: 42,
            action: 'BEFORE',
            performedBy: 'Alice',
            performedByNickId: 42,
            message: 'existing record',
            performedAt: new DateTimeImmutable('2026-01-01 UTC'),
        ));

        $failingRepository = new class($repository) implements NickHistoryRepositoryInterface {
            public function __construct(private readonly NickHistoryDoctrineRepository $inner) {}

            public function save(NickHistory $history): void
            {
                throw new RuntimeException('history insert failed');
            }

            public function findById(int $id): ?NickHistory
            {
                return $this->inner->findById($id);
            }

            public function findByNickId(int $nickId, ?int $limit = null, int $offset = 0): array
            {
                return $this->inner->findByNickId($nickId, $limit, $offset);
            }

            public function countByNickId(int $nickId): int
            {
                return $this->inner->countByNickId($nickId);
            }

            public function deleteById(int $id): bool
            {
                return $this->inner->deleteById($id);
            }

            public function deleteByNickId(int $nickId): int
            {
                return $this->inner->deleteByNickId($nickId);
            }

            public function deleteOlderThan(DateTimeImmutable $threshold): int
            {
                return $this->inner->deleteOlderThan($threshold);
            }
        };

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($account);
        $transactionManager = new DoctrineTransactionManager($this->entityManager);
        $handler = new HistoryNickHandler(
            $nickRepository,
            $failingRepository,
            new NickHistoryService($failingRepository),
            new NickDoctrineTransactionBoundary($transactionManager),
        );

        try {
            $handler->handle(new HistoryNick(
                nickname: 'Alice',
                action: HistoryNickAction::Clear,
                occurredAt: new DateTimeImmutable('2026-01-02 UTC'),
                operatorNick: 'Oper',
            ));
            self::fail('The replacement history insertion must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('history insert failed', $exception->getMessage());
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT action, message FROM nick_history WHERE nick_id = 42');
        self::assertSame([['action' => 'BEFORE', 'message' => 'existing record']], $rows);
    }
}
