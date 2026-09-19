<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface EmailChangeMailSender
{
    public function sendVerification(
        string $currentEmail,
        string $nickname,
        string $newEmail,
        string $token,
        string $locale,
    ): void;
}
