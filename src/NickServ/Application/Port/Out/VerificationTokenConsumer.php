<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use DateTimeImmutable;

interface VerificationTokenConsumer
{
    public function consume(string $nickname, string $token, DateTimeImmutable $now): bool;
}
