<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

enum DatabaseMutationFailure
{
    case Rejected;
    case UnsupportedRecordType;
    case InvalidPath;
    case InvalidValue;
}
