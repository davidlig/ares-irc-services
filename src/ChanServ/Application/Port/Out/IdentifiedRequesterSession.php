<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface IdentifiedRequesterSession
{
    public function isIdentifiedAs(string $uid, string $registeredNickname): bool;
}
