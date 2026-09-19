<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAccess;

interface ManageChannelAccessHandlerInterface
{
    public function handle(ManageChannelAccess $command): ManageChannelAccessResult;
}
