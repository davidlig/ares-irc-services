<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface RecoveryMailSender
{
    public function sendRecovery(string $nickname, string $email, string $token, string $locale): void;
}
