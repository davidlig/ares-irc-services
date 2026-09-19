<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLifecycle;

interface ManageChannelLifecycleHandlerInterface
{
    public function handle(ManageChannelLifecycle $command): ChannelLifecycleResult;
}
