<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ;

use App\Application\NickServ\SessionLanguageRegistry;
use App\Application\Port\SenderView;
use App\Application\Port\UserLanguageResolverInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;

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
readonly class UserLanguageResolver implements UserLanguageResolverInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private SessionLanguageRegistry $sessionLanguageRegistry,
        private string $defaultLanguage = 'en',
    ) {}

    public function resolve(SenderView $user): string
    {
        $account = $this->nickRepository->findByNick($user->nick);
        if (null !== $account) {
            return $account->getLanguage();
        }

        return $this->sessionLanguageRegistry->find($user->uid) ?? $this->defaultLanguage;
    }

    /**
     * Resolves language when the caller already has the RegisteredNick entity,
     * avoiding a second findByNick call.
     */
    public function resolveFromAccount(SenderView $user, ?RegisteredNick $account): string
    {
        if (null !== $account) {
            return $account->getLanguage();
        }

        return $this->sessionLanguageRegistry->find($user->uid) ?? $this->defaultLanguage;
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
