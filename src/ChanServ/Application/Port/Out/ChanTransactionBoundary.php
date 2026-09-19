<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChanTransactionBoundary
{
    public function transactional(callable $operation): mixed;

    public function afterCommit(callable $operation): void;
}
