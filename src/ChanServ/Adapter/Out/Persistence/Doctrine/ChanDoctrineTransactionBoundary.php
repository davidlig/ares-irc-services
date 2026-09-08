<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Application\Port\TransactionManagerInterface;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;

final readonly class ChanDoctrineTransactionBoundary implements ChanTransactionBoundary
{
    public function __construct(private TransactionManagerInterface $transactionManager) {}

    public function transactional(callable $operation): mixed
    {
        return $this->transactionManager->transactional($operation);
    }

    public function afterCommit(callable $operation): void
    {
        $this->transactionManager->afterCommit($operation);
    }
}
