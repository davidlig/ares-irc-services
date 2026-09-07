<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

interface ChanServUserPresentationPreferences
{
    public function languageFor(string $uid, string $nickname, ?string $accountLanguage = null): string;

    public function defaultLanguage(): string;

    public function prefersPrivateMessages(string $nickname): bool;
}
