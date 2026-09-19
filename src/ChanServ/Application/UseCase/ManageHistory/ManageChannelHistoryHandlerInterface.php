<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

interface ManageChannelHistoryHandlerInterface
{
    public function handle(ManageChannelHistory $command): ManageChannelHistoryResult;
}
