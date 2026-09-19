<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\Out\NickTransactionBoundary;
use App\Shared\Application\Port\TransactionManagerInterface;

final readonly class NickDoctrineTransactionBoundary implements NickTransactionBoundary
{
    public function __construct(private TransactionManagerInterface $transactionManager) {}

    public function transactional(callable $operation): mixed
    {
        return $this->transactionManager->transactional($operation);
    }
}
