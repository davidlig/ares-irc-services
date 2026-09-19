<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Resend;

interface ResendVerificationHandlerInterface
{
    public function handle(ResendVerification $command): ResendVerificationResult;
}
