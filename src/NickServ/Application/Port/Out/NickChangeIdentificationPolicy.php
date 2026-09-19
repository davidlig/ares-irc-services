<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface NickChangeIdentificationPolicy
{
    public function preservesIdentification(): bool;
}
