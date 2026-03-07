<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\NetworkUser;

/**
 * Dispatched when a UID command is received, meaning a user has joined the network
 * (either during server burst or a new connection after sync).
 */
final readonly class UserJoinedNetworkEvent
{
    public function __construct(public readonly NetworkUser $user)
    {
    }
}
