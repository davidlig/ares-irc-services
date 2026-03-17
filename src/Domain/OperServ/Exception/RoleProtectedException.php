<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Exception;

use Exception;

use function sprintf;

final class RoleProtectedException extends Exception
{
    public static function forRole(string $roleName): self
    {
        return new self(sprintf('The role "%s" is protected and cannot be deleted.', $roleName));
    }
}
