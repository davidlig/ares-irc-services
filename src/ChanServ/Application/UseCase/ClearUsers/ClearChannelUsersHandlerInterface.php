<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearUsers;

interface ClearChannelUsersHandlerInterface
{
    public function handle(ClearChannelUsers $command): ClearChannelUsersResult;
}
