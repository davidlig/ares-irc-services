<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

enum NickProtectabilityStatus
{
    case Allowed;
    case IsRoot;
    case IsIrcop;
    case IsService;
}
