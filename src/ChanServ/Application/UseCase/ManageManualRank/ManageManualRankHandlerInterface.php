<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageManualRank;

interface ManageManualRankHandlerInterface
{
    public function handle(ManageManualRank $command): ManageManualRankResult;
}
