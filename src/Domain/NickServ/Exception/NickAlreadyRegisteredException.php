<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Exception;

final class NickAlreadyRegisteredException extends \DomainException
{
    public function __construct(string $nickname)
    {
        parent::__construct(sprintf('Nickname "%s" is already registered.', $nickname));
    }
}
