<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

interface ManageChannelAkickHandlerInterface
{
    public function handle(ManageChannelAkick $command): ManageChannelAkickResult;
}
