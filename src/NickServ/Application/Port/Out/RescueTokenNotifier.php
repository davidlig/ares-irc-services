<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\Model\UserMessagePreference;

interface RescueTokenNotifier
{
    public function issued(string $uid, string $token, string $language, UserMessagePreference $preference): void;
}
