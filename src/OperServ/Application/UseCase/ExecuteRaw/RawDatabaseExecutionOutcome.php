<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ExecuteRaw;

enum RawDatabaseExecutionOutcome
{
    case Executed;
    case DatabaseExecuted;
    case Empty;
    case TooLong;
    case Disconnected;
    case DatabaseTargetInvalid;
    case DatabaseSyntaxInvalid;
    case DatabaseUnsupported;
    case DatabaseRecordTypeInvalid;
    case DatabasePathInvalid;
    case DatabaseValueInvalid;
    case DatabaseFailed;
}
