<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLevels;

interface ManageChannelLevelsHandlerInterface
{
    public function handle(ManageChannelLevels $command): ManageChannelLevelsResult;
}
