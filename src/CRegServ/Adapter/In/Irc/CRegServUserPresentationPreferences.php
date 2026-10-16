<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc;

interface CRegServUserPresentationPreferences
{
    public function languageFor(string $uid, string $nickname, ?string $accountLanguage = null): string;

    public function prefersPrivateMessages(string $nickname): bool;
}
