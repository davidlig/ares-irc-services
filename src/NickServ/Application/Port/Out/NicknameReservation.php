<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface NicknameReservation
{
    public function reserve(string $nickname, string $reason): void;

    public function release(string $nickname): void;
}
