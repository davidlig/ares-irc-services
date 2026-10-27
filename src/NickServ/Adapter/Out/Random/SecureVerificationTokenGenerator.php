<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Random;

use App\NickServ\Application\Port\Out\VerificationTokenGenerator;

use function bin2hex;
use function random_bytes;

final readonly class SecureVerificationTokenGenerator implements VerificationTokenGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
