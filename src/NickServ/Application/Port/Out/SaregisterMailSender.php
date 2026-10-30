<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface SaregisterMailSender
{
    public function sendRescueToken(string $nickname, string $email, string $token, string $locale): void;
}
