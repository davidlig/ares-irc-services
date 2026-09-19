<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\PrepareInvite;

interface PrepareChannelInviteHandlerInterface
{
    public function handle(PrepareChannelInvite $query): PrepareChannelInviteResult;
}
