<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

final class OperatorAuthorizationAttribute
{
    public const string IDENTIFIED = 'IDENTIFIED';

    public const string ROOT = 'ROOT';

    private function __construct() {}
}
