<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

/**
 * Validates nickname syntax and maximum length bounds for NickServ registrations.
 */
interface NicknamePolicyInterface
{
    public function isValid(string $nickname): bool;

    public function getMaximumLength(): int;
}
