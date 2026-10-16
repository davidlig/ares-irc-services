<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\NickServ;

use App\CRegServ\Adapter\In\Irc\CRegServUserPresentationPreferences;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;

final readonly class NickServCRegServUserPreferencesAdapter implements CRegServUserPresentationPreferences
{
    public function __construct(
        private UserLanguageQuery $languageQuery,
        private UserMessagePreferenceQuery $messagePreferenceQuery,
    ) {}

    public function languageFor(string $uid, string $nickname, ?string $accountLanguage = null): string
    {
        return null !== $accountLanguage
            ? $this->languageQuery->resolveFromAccount($uid, $accountLanguage)
            : $this->languageQuery->resolve($uid, $nickname);
    }

    public function prefersPrivateMessages(string $nickname): bool
    {
        return $this->messagePreferenceQuery->prefersPrivateMessages($nickname);
    }
}
