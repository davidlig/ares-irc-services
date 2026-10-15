<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ListChannelRegistrations;

interface ListChannelRegistrationsHandlerInterface
{
    public function handle(ListChannelRegistrations $command): ListChannelRegistrationsResult;
}
