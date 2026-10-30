<?php

declare(strict_types=1);

namespace App\Shared\Adapter\In\Irc\Presentation;

enum Role: string
{
    case Success = 'success';
    case Error = 'error';
    case Warning = 'warning';
    case Information = 'information';
}
