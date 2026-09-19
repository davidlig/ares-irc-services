<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\Model\UserMessagePreference;

interface NickProtectionNotifier
{
    public function notifyForbidden(string $uid, string $nickname, string $reason, string $language): void;

    public function notifyRename(
        string $uid,
        string $nickname,
        string $guestNickname,
        string $language,
        UserMessagePreference $preference,
    ): void;
}
