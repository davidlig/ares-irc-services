<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc;

interface CRegServAuthorizationCheckerInterface
{
    public function isGranted(string $permission, mixed $subject = null): bool;
}
