<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

interface GlineNetworkActions
{
    public function add(string $mask, ?DateTimeImmutable $expiresAt, string $reason): void;

    public function remove(string $mask): void;
}
