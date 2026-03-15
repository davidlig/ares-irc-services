<?php

declare(strict_types=1);

namespace App\Application\Event;

use App\Application\Port\UserJoinedNetworkDTO;

/**
 * Application-layer event dispatched when a user joins the IRC network.
 *
 * This is the Services-facing event that carries a DTO instead of a Domain entity.
 * Core Infrastructure dispatches this after the Core Domain event.
 * Services (NickServ, ChanServ, MemoServ) subscribe to this event.
 */
final readonly class UserJoinedNetworkAppEvent
{
    public function __construct(public readonly UserJoinedNetworkDTO $user)
    {
    }
}
