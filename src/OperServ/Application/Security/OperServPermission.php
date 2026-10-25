<?php

declare(strict_types=1);

namespace App\OperServ\Application\Security;

final class OperServPermission
{
    public const string KILL = 'operserv.kill';

    public const string GLINE = 'operserv.gline';

    public const string GLOBAL = 'operserv.global';

    public const string RAW = 'operserv.raw';

    public const string MOTD = 'operserv.motd';

    public const string BADMAIL = 'operserv.badmail';

    private function __construct() {}
}
