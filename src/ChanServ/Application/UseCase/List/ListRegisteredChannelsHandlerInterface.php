<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\List;

interface ListRegisteredChannelsHandlerInterface
{
    public function handle(ListRegisteredChannels $query): ListRegisteredChannelsResult;
}
