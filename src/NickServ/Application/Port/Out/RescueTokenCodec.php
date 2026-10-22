<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface RescueTokenCodec
{
    public function generate(): string;

    public function hash(string $token): ?string;

    public function matches(string $token, string $hash): bool;
}
