<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface ResendMailSender
{
    public function sendResend(string $nickname, string $email, string $token, string $locale): void;
}
