<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Random;

use App\ChanServ\Application\Port\Out\FounderChangeTokenGenerator;

use function bin2hex;
use function random_bytes;

final readonly class SecureFounderChangeTokenGenerator implements FounderChangeTokenGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
