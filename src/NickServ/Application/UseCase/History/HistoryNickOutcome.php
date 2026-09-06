<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\History;

enum HistoryNickOutcome
{
    case NotRegistered;
    case AddSuccess;
    case DelInvalidId;
    case DelNotFound;
    case DelSuccess;
    case ClearSuccess;
    case ViewNoEntries;
    case ViewSuccess;
}
