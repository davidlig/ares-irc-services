<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

interface UserMessagePreferenceQuery
{
    public function prefersPrivateMessages(string $nickname): bool;
}
