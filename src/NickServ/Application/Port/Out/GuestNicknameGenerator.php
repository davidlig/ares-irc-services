<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface GuestNicknameGenerator
{
    public function generate(string $prefix): string;
}
