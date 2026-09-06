<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\History;

enum HistoryNickAction: string
{
    case Add = 'ADD';
    case Del = 'DEL';
    case View = 'VIEW';
    case Clear = 'CLEAR';
}
