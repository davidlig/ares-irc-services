<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UpdateSetting;

enum UpdateChannelSettingOutcome
{
    case Updated;
    case Cleared;
    case MissingValue;
    case InvalidEmail;
    case EntryMessageTooLong;
    case SuccessorNotFound;
    case SuccessorSuspended;
    case SuccessorNotRegistered;
    case SuccessorIsFounder;
}
