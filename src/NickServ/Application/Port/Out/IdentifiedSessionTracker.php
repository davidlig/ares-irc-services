<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface IdentifiedSessionTracker
{
    public function register(string $uid, string $registeredNick): void;

    public function findNick(string $uid): ?string;

    public function findUidByNick(string $registeredNick): ?string;

    public function remove(string $uid): void;
}
