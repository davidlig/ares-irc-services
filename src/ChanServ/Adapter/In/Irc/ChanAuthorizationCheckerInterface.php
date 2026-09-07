<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

interface ChanAuthorizationCheckerInterface
{
    public function isGranted(string $permission, mixed $subject = null): bool;
}
