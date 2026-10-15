<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ApproveChannelRegistration;

interface ApproveChannelRegistrationHandlerInterface
{
    public function handle(ApproveChannelRegistration $command): ApproveChannelRegistrationResult;
}
