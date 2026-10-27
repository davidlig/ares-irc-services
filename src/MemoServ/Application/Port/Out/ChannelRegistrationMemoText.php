<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Port\Out;

use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecision;

interface ChannelRegistrationMemoText
{
    /** @return non-empty-list<string> Memo-sized UTF-8 messages in recipient language. */
    public function render(NotifyChannelRegistrationDecision $decision, string $language): array;
}
