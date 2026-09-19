<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface NojoinActions
{
    public function kick(
        string $channelName,
        string $uid,
        string $nickname,
        int $effectiveAccess,
        int $requiredAccess,
        string $language,
    ): void;
}
