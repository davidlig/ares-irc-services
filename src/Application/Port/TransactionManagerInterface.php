<?php

declare(strict_types=1);

namespace App\Application\Port;

interface TransactionManagerInterface
{
    public function transactional(callable $operation): mixed;

    public function afterCommit(callable $operation): void;
}
