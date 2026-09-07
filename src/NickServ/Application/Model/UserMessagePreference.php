<?php

declare(strict_types=1);

namespace App\NickServ\Application\Model;

enum UserMessagePreference
{
    case Notice;
    case PrivateMessage;
}
