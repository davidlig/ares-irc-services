<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ReviewRegistration;

final readonly class ReviewRegistrationResult
{
    public function __construct(public ReviewRegistrationOutcome $outcome) {}
}
