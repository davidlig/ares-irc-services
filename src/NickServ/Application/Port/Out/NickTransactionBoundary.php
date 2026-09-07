<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface NickTransactionBoundary
{
    public function transactional(callable $operation): mixed;
}
