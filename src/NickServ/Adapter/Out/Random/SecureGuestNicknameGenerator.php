<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Random;

use App\NickServ\Application\Port\Out\GuestNicknameGenerator;

use function bin2hex;
use function random_bytes;
use function strtoupper;
use function substr;

final readonly class SecureGuestNicknameGenerator implements GuestNicknameGenerator
{
    public function generate(string $prefix): string
    {
        return $prefix . strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));
    }
}
