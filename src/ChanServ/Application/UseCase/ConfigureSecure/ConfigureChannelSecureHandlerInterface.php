<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ConfigureSecure;

interface ConfigureChannelSecureHandlerInterface
{
    public function handle(ConfigureChannelSecure $command): void;
}
