<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
