<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Random;

use App\NickServ\Application\Port\Out\VerificationTokenGenerator;

final readonly class SecureVerificationTokenGenerator implements VerificationTokenGenerator
{
    public function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
