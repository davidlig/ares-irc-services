<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ReviewRegistration;

interface ReviewRegistrationHandlerInterface
{
    public function handle(ReviewRegistration $command): ReviewRegistrationResult;
}
