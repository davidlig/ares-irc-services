<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface RecoveryPasswordGenerator
{
    public function generate(): string;
}
