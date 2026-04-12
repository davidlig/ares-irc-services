<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Security;

final readonly class ChanServPermission
{
    public const string DROP = 'chanserv.drop';

    public const string SUSPEND = 'chanserv.suspend';

    public const string FORBID = 'chanserv.forbid';

    public const string LEVEL_FOUNDER = 'chanserv.level_founder';
}
