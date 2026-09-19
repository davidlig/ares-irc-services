<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface RegistrationMailSender
{
    public function sendVerification(string $nickname, string $email, string $token, string $locale): void;
}
