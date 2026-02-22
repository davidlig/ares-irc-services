<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Exception;

use DomainException;

final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Invalid nickname or password.');
    }
}
