<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\ChanServ;

use App\Application\ChanServ\Port\In\NickChannelAssociation;
use App\Application\ChanServ\Port\In\NickChannelAssociationQuery;
use App\NickServ\Application\Port\Out\AssociatedChannel;
use App\NickServ\Application\Port\Out\NickAssociatedChannelsPort;

final readonly class ChanServNickAssociatedChannelsAdapter implements NickAssociatedChannelsPort
{
    public function __construct(private NickChannelAssociationQuery $query) {}

    public function findChannelsForNick(int $nickId): array
    {
        return array_map(
            static fn (NickChannelAssociation $channel): AssociatedChannel => new AssociatedChannel($channel->name, $channel->type, $channel->level),
            $this->query->findForNick($nickId),
        );
    }
}
