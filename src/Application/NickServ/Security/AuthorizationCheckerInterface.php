<?php

declare(strict_types=1);

namespace App\Application\NickServ\Security;

/**
 * Port for authorization checks (e.g. Symfony Security isGranted).
 * Implemented in Infrastructure using Symfony Security Core.
 */
interface AuthorizationCheckerInterface
{
    public function isGranted(string $attribute, mixed $subject = null): bool;
}
