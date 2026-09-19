<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\Model\NetworkUser;

interface NickNetworkUserLookup
{
    public function findByUid(string $uid): ?NetworkUser;

    public function findByNick(string $nick): ?NetworkUser;
}
