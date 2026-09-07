<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Port\In;

interface NickChannelAssociationQuery
{
    /** @return list<NickChannelAssociation> */
    public function findForNick(int $nickId): array;
}
