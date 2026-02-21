<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ;

use App\Domain\IRC\Network\NetworkUser;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

/**
 * Resolves the preferred language for any IRC user.
 *
 * Resolution order:
 *   1. If the user has a registered account → use the language stored there.
 *   2. Fall back to the configured default language.
 *
 * This service is shared across all IRC services (NickServ, ChanServ, etc.)
 * so they all use the same language preference transparently.
 */
readonly class UserLanguageResolver
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private string $defaultLanguage = 'en',
    ) {
    }

    public function resolve(NetworkUser $user): string
    {
        $account = $this->nickRepository->findByNick($user->getNick()->value);

        return $account?->getLanguage() ?? $this->defaultLanguage;
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
