<?php

declare(strict_types=1);

namespace App\NickServ\Domain\Exception;

use DomainException;

final class InvalidCredentialsException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Invalid nickname or password.');
    }
}
