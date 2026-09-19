<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

enum SetNickSettingOption: string
{
    case Password = 'PASSWORD';
    case Email = 'EMAIL';
    case Language = 'LANGUAGE';
    case Timezone = 'TIMEZONE';
    case PrivateMode = 'PRIVATE';
    case MessageMode = 'MSG';
    case Vhost = 'VHOST';
}
