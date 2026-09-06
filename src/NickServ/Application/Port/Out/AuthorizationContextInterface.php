<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\Irc\Application\Port\In\SenderView;

/**
 * Port for setting the current IRC user in the authorization layer (e.g. Security token).
 * Call setCurrentUser() before permission checks, clear() after.
 * Implemented in Infrastructure using Symfony TokenStorage.
 */
interface AuthorizationContextInterface
{
    public function setCurrentUser(SenderView $user): void;

    public function clear(): void;
}
