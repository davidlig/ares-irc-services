<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingNickNotice;

enum GetPendingNickNoticeOutcome
{
    case NoNotice;
    case PendingMemos;
}
