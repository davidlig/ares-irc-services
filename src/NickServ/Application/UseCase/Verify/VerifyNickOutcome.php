<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Verify;

enum VerifyNickOutcome
{
    case Success;
    case NoPending;
    case InvalidToken;
}
