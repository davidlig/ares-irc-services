<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

interface MemoServUserPresentationPreferences
{
    public function languageFor(string $uid, string $nickname, ?string $accountLanguage = null): string;

    public function prefersPrivateMessages(string $nickname): bool;
}
