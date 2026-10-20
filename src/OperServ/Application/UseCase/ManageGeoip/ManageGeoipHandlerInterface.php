<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGeoip;

interface ManageGeoipHandlerInterface
{
    public function handle(ManageGeoip $command): ManageGeoipResult;
}
