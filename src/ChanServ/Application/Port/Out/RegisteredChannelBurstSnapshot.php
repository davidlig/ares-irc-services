<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface RegisteredChannelBurstSnapshot
{
    public function beginBurst(): void;

    public function endBurst(): void;
}
