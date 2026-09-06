<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface VerificationTokenConsumer
{
    public function consume(string $nickname, string $token): bool;
}
