<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

enum NickProtectabilityStatus
{
    case Allowed;
    case IsRoot;
    case IsIrcop;
    case IsService;
}
