<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\OperServ\Application\Port\Out\NetworkUser;
use App\OperServ\Application\Port\Out\NetworkUserLookup;

/** Adapts the public Irc connected-user query to the OperServ consumer-owned port. */
final readonly class IrcNetworkUserLookup implements NetworkUserLookup
{
    public function __construct(private NetworkUserLookupPort $users) {}

    public function findByNickname(string $nickname): ?NetworkUser
    {
        $user = $this->users->findByNick($nickname);

        return null === $user ? null : new NetworkUser(
            $user->uid,
            $user->nick,
            $user->ident,
            $user->hostname,
            $user->ipBase64,
            $user->isIdentified,
            $user->isOper,
        );
    }
}
