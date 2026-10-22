<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\RegistrationMode;

use App\NickServ\Domain\ValueObject\RegistrationMode;

final readonly class RegistrationModeInput
{
    /** $authorized must come from the centralized operator permission boundary. */
    public function __construct(public bool $authorized, public ?RegistrationMode $mode = null) {}
}
