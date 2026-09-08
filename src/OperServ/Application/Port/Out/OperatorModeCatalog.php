<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorModeCatalog
{
    /** @return list<string>|null Null when user-mode management is unavailable. */
    public function available(): ?array;
}
