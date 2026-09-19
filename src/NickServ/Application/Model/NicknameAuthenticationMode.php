<?php

declare(strict_types=1);

namespace App\NickServ\Application\Model;

enum NicknameAuthenticationMode
{
    case ServiceCommand;
    case NativeNick;
}
