<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageEmailVerification;

interface ManageEmailVerificationHandlerInterface
{
    public function handle(ManageEmailVerification $command): ManageEmailVerificationResult;
}
