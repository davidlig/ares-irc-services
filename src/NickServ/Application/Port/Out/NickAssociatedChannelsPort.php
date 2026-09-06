<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface NickAssociatedChannelsPort
{
    /**
     * @return list<AssociatedChannel>
     */
    public function findChannelsForNick(int $nickId): array;
}
