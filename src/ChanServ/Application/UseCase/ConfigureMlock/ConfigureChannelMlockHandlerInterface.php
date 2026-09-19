<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ConfigureMlock;

interface ConfigureChannelMlockHandlerInterface
{
    public function handle(ConfigureChannelMlock $command): ConfigureChannelMlockResult;
}
