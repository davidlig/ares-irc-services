<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

interface MemoAuthorizationCheckerInterface
{
    public function isGranted(string $permission, mixed $subject = null): bool;
}
