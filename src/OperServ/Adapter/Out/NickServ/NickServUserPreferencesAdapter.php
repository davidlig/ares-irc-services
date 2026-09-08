<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\NickServ;

use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\OperServ\Application\Port\Out\ServiceUserPreferences;

final readonly class NickServUserPreferencesAdapter implements ServiceUserPreferences
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

    public function defaultLanguage(): string
    {
        return $this->languageQuery->getDefault();
    }

    public function prefersPrivateMessages(string $nickname): bool
    {
        return $this->messagePreferenceQuery->prefersPrivateMessages($nickname);
    }
}
