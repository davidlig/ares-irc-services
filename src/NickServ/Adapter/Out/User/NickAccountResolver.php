<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

final readonly class NickAccountResolver implements NickAccountQuery
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private string $defaultLanguage = 'en',
    ) {}

    public function findIdByNick(string $nickname): ?int
    {
        return $this->nickRepository->findByNick($nickname)?->getId();
    }

    public function findNicknameById(int $id): ?string
    {
        return $this->nickRepository->findById($id)?->getNickname();
    }

    public function findAccountByNick(string $nickname): ?NickAccountData
    {
        $account = $this->nickRepository->findByNick($nickname);
        if (null === $account) {
            return null;
        }

        return new NickAccountData(
            id: $account->getId(),
            nickname: $account->getNickname(),
            language: $account->getLanguage(),
            timezone: $account->getTimezone() ?? 'UTC',
            registered: $account->isRegistered(),
            suspended: $account->isSuspended(),
            email: $account->getEmail(),
        );
    }

    public function findAccountById(int $id): ?NickAccountData
    {
        $account = $this->nickRepository->findById($id);
        if (null === $account) {
            return null;
        }

        return new NickAccountData(
            id: $account->getId(),
            nickname: $account->getNickname(),
            language: $account->getLanguage(),
            timezone: $account->getTimezone() ?? 'UTC',
            registered: $account->isRegistered(),
            suspended: $account->isSuspended(),
            email: $account->getEmail(),
        );
    }

    public function getLanguage(int $id): string
    {
        $account = $this->nickRepository->findById($id);

        return $account?->getLanguage() ?? $this->defaultLanguage;
    }
}
