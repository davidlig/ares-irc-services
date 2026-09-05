<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Persistence;

use App\Infrastructure\Persistence\DoctrineTransactionManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DoctrineTransactionManager::class)]
final class DoctrineTransactionManagerTest extends TestCase
{
    #[Test]
    public function returnsResultAndRunsQueuedCallbacksAfterCommit(): void
    {
        $calls = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static function (callable $operation) use (&$calls): string {
                $result = $operation();
                $calls[] = 'commit';

                return $result;
            },
        );
        $manager = new DoctrineTransactionManager($entityManager);

        $result = $manager->transactional(static function () use ($manager, &$calls): string {
            $calls[] = 'operation';
            $manager->afterCommit(static function () use (&$calls): void {
                $calls[] = 'after-commit';
            });

            return 'result';
        });

        self::assertSame('result', $result);
        self::assertSame(['operation', 'commit', 'after-commit'], $calls);
    }

    #[Test]
    public function clearsQueuedCallbacksOnRollbackAndRethrows(): void
    {
        $calls = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static function (callable $operation) use (&$calls): never {
                $operation();
                $calls[] = 'rollback';

                throw new RuntimeException('rollback');
            },
        );
        $manager = new DoctrineTransactionManager($entityManager);

        try {
            $manager->transactional(static function () use ($manager, &$calls): void {
                $calls[] = 'operation';
                $manager->afterCommit(static function () use (&$calls): void {
                    $calls[] = 'must-not-run';
                });
            });
            self::fail('The transaction exception was not rethrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }

        self::assertSame(['operation', 'rollback'], $calls);
    }

    #[Test]
    public function nestedTransactionsReuseOuterDoctrineTransaction(): void
    {
        $calls = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(
            static function (callable $operation) use (&$calls): mixed {
                $result = $operation();
                $calls[] = 'commit';

                return $result;
            },
        );
        $manager = new DoctrineTransactionManager($entityManager);

        $manager->transactional(static function () use ($manager, &$calls): void {
            $calls[] = 'outer';
            $manager->transactional(static function () use (&$calls): void {
                $calls[] = 'inner';
            });
            $manager->afterCommit(static function () use (&$calls): void {
                $calls[] = 'after-commit';
            });
        });

        self::assertSame(['outer', 'inner', 'commit', 'after-commit'], $calls);
    }

    #[Test]
    public function runsAfterCommitCallbackImmediatelyOutsideTransaction(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $manager = new DoctrineTransactionManager($entityManager);
        $called = false;

        $manager->afterCommit(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }
}
