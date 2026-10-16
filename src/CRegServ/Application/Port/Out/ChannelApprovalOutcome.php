<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Port\Out;

enum ChannelApprovalOutcome
{
    case Approved;
    case PendingDeletion;
    case AlreadyRegistered;
    case Failed;
}
