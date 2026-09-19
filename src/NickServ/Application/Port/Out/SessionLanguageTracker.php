<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface SessionLanguageTracker
{
    public function register(string $uid, string $language): void;

    public function find(string $uid): ?string;

    public function remove(string $uid): void;
}
