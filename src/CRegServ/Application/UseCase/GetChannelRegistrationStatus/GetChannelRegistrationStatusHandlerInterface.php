<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\GetChannelRegistrationStatus;

interface GetChannelRegistrationStatusHandlerInterface
{
    public function handle(GetChannelRegistrationStatus $command): GetChannelRegistrationStatusResult;
}
