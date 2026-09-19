<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface FounderChangeTokenGenerator
{
    public function generate(): string;
}
