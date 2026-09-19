<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

enum SetNickSettingOutcome
{
    case Changed;
    case SessionLanguageChanged;
    case EmailConfirmationSent;
    case TargetNotRegistered;
    case TargetIsRoot;
    case TargetIsIrcop;
    case TargetIsService;
    case MissingValue;
    case InvalidEmail;
    case EmailAlreadyUsed;
    case NoCurrentEmail;
    case InvalidEmailToken;
    case MailDeliveryFailed;
    case InvalidLanguage;
    case InvalidFlag;
    case InvalidTimezone;
    case ForcedVhost;
    case InvalidVhost;
    case VhostTaken;
}
