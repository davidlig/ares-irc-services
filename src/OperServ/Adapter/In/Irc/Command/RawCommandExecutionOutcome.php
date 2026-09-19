<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

enum RawCommandExecutionOutcome
{
    case Sent;
    case Intercepted;
    case Empty;
    case TooLong;
    case Disconnected;
    case TargetInvalid;
    case SyntaxInvalid;
    case Unsupported;
    case ResourceTypeInvalid;
    case ResourceIdentifierInvalid;
    case ValueInvalid;
    case Failed;
}
