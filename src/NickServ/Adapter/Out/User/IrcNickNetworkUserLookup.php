<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;

final readonly class IrcNickNetworkUserLookup implements NickNetworkUserLookup
{
    public function __construct(private NetworkUserLookupPort $userLookup) {}

    public function findByUid(string $uid): ?NetworkUser
    {
        $user = $this->userLookup->findByUid($uid);

        return null === $user ? null : IrcNetworkUserMapper::map($user);
    }

    public function findByNick(string $nick): ?NetworkUser
    {
        $user = $this->userLookup->findByNick($nick);

        return null === $user ? null : IrcNetworkUserMapper::map($user);
    }
}
