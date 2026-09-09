<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

enum RawCommandInterceptionFailure
{
    case TargetInvalid;
    case SyntaxInvalid;
    case Unsupported;
    case ResourceTypeInvalid;
    case ResourceIdentifierInvalid;
    case ValueInvalid;
    case Rejected;
}
