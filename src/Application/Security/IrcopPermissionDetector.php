<?php

declare(strict_types=1);

namespace App\Application\Security;

final class IrcopPermissionDetector
{
    public function isIrcopPermission(string $permission): bool
    {
        if ('IDENTIFIED' === $permission) {
            return false;
        }

        return 1 === preg_match('/^[a-z]+\.[a-z._]+$/', $permission);
    }
}
