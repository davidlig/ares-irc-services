<?php

declare(strict_types=1);

namespace App\Bootstrap\Adapter\Out\Persistence;

use App\Shared\Application\Port\TransactionManagerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final class DoctrineTransactionManager implements TransactionManagerInterface
{
    /** @var list<callable(): void> */
    private array $afterCommit = [];

    private bool $transactionActive = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function transactional(callable $operation): mixed
    {
        if ($this->transactionActive) {
            return $operation();
        }

        $this->transactionActive = true;

        try {
            $result = $this->entityManager->wrapInTransaction(
                static fn (): mixed => $operation(),
            );
        } catch (Throwable $exception) {
            $this->afterCommit = [];

            throw $exception;
        } finally {
            $this->transactionActive = false;
        }

        $afterCommit = $this->afterCommit;
        $this->afterCommit = [];

        foreach ($afterCommit as $callback) {
            $callback();
        }

        return $result;
    }

    public function afterCommit(callable $operation): void
    {
        if (!$this->transactionActive) {
            $operation();

            return;
        }

        $this->afterCommit[] = $operation;
    }
}
