<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Model\NetworkUser;

final readonly class IrcNetworkUserMapper
{
    public static function map(SenderView $user): NetworkUser
    {
        return new NetworkUser(
            uid: $user->uid,
            nick: $user->nick,
            ident: $user->ident,
            hostname: $user->hostname,
            cloakedHost: $user->cloakedHost,
            ipBase64: $user->ipBase64,
            isIdentified: $user->isIdentified,
            isOper: $user->isOper,
            serverSid: $user->serverSid,
            displayHost: $user->displayHost,
            modes: $user->modes,
        );
    }
}
