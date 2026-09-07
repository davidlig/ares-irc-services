<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Application\Port\TransactionManagerInterface;
use App\NickServ\Application\Port\Out\NickTransactionBoundary;

final readonly class NickDoctrineTransactionBoundary implements NickTransactionBoundary
{
    public function __construct(private TransactionManagerInterface $transactionManager) {}

    public function transactional(callable $operation): mixed
    {
        return $this->transactionManager->transactional($operation);
    }
}
