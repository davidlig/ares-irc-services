<?php

declare(strict_types=1);

namespace App\NickServ\Domain\Exception;

use DomainException;

use function sprintf;

final class NickNotRegisteredException extends DomainException
{
    public function __construct(string $nickname)
    {
        parent::__construct(sprintf('Nickname "%s" is not registered.', $nickname));
    }
}
