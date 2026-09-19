<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

/**
 * Application-layer event dispatched when a user joins the IRC network.
 *
 * This is the Services-facing event that carries a DTO instead of a Domain entity.
 * Core Infrastructure dispatches this after the Core Domain event.
 * Services (NickServ, ChanServ, MemoServ) subscribe to this event.
 */
final readonly class UserJoinedNetworkAppEvent
{
    public function __construct(public UserJoinedNetworkDTO $user) {}
}
