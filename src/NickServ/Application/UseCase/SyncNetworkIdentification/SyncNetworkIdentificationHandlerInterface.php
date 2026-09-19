<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\SyncNetworkIdentification;

interface SyncNetworkIdentificationHandlerInterface
{
    public function handle(SyncNetworkIdentification $command): void;
}
