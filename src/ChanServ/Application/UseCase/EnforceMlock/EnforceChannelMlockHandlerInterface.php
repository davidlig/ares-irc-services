<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceMlock;

use App\ChanServ\Application\Model\ChannelMlockPolicy;

interface EnforceChannelMlockHandlerInterface
{
    public function handle(EnforceChannelMlock $command): MlockEnforcementResult;

    public function handleKnownPolicy(EnforceChannelMlock $command, ChannelMlockPolicy $channel): MlockEnforcementResult;
}
