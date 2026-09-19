<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\Out\GlineUser;
use App\OperServ\Application\Port\Out\GlineUserLookup;

final readonly class NetworkGlineUserLookup implements GlineUserLookup
{
    public function __construct(
        private NetworkUserLookupPort $networkUsers,
        private NickAccountQuery $accounts,
    ) {}

    public function findByNickname(string $nickname): ?GlineUser
    {
        $user = $this->networkUsers->findByNick($nickname);

        return null === $user ? null : new GlineUser($user->nick, $user->ident, $user->hostname);
    }

    public function findNicknameByAccountId(int $accountId): ?string
    {
        return $this->accounts->findNicknameById($accountId);
    }
}
