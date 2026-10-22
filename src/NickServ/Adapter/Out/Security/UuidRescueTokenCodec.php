<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\NickServ\Application\Port\Out\RescueTokenCodec;

use function chr;
use function ord;

final class UuidRescueTokenCodec implements RescueTokenCodec
{
    public function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function hash(string $token): ?string
    {
        $canonical = strtolower(trim($token));
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $canonical)) {
            return null;
        }

        return hash('sha256', $canonical);
    }

    public function matches(string $token, string $hash): bool
    {
        $candidate = $this->hash($token);

        return null !== $candidate && hash_equals($hash, $candidate);
    }
}
