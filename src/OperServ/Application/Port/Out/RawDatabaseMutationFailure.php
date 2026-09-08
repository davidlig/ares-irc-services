<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

enum RawDatabaseMutationFailure
{
    case UnsupportedRecordType;
    case InvalidRecordPath;
    case InvalidRecordValue;
    case Rejected;
}
