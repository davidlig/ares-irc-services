<?php

declare(strict_types=1);

namespace App\Application\OperServ\Security;

final class OperServPermission
{
    public const string KILL = 'operserv.kill';

    public const string GLINE = 'operserv.gline';

    private function __construct()
    {
    }
}
