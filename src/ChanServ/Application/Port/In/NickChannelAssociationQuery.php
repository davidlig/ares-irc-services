<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

interface NickChannelAssociationQuery
{
    /** @return list<NickChannelAssociation> */
    public function findForNick(int $nickId): array;
}
