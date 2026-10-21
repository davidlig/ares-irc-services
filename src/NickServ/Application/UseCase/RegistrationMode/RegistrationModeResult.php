<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\RegistrationMode;

use App\NickServ\Domain\ValueObject\RegistrationMode;

final readonly class RegistrationModeResult
{
    public function __construct(public bool $authorized, public ?RegistrationMode $mode = null, public bool $changed = false) {}
}
