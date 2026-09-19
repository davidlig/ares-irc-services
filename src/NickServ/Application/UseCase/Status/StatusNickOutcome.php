<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Status;

enum StatusNickOutcome
{
    case UnregisteredOffline;
    case UnregisteredOnline;
    case Pending;
    case RegisteredNotConnected;
    case RegisteredNotIdentified;
    case RegisteredIdentified;
    case Suspended;
    case Forbidden;
    case PendingDeletion;
}
