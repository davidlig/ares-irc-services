<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

/**
 * Resolves the preferred language for any IRC user.
 *
 * Resolution order:
 *   1. If the user has a registered account → use the language stored there.
 *   2. If the user has a temporary session language → use that.
 *   3. Fall back to the configured default language.
 *
 * This service is shared across all IRC services (NickServ, ChanServ, etc.)
 * so they all use the same language preference transparently.
 */
readonly class UserLanguageResolver implements UserLanguageQuery
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private SessionLanguageRegistry $sessionLanguageRegistry,
        private string $defaultLanguage = 'en',
    ) {}

    public function resolve(string $uid, string $nickname): string
    {
        $account = $this->nickRepository->findByNick($nickname);
        if (null !== $account) {
            return $account->getLanguage();
        }

        return $this->sessionLanguageRegistry->find($uid) ?? $this->defaultLanguage;
    }

    public function resolveFromAccount(string $uid, ?string $accountLanguage): string
    {
        if (null !== $accountLanguage) {
            return $accountLanguage;
        }

        return $this->sessionLanguageRegistry->find($uid) ?? $this->defaultLanguage;
    }

    public function resolveByNick(string $nick): string
    {
        $account = $this->nickRepository->findByNick($nick);

        return $account?->getLanguage() ?? $this->defaultLanguage;
    }

    public function getDefault(): string
    {
        return $this->defaultLanguage;
    }
}
