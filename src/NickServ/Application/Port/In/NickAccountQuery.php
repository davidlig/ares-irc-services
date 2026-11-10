<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

interface NickAccountQuery
{
    public function findIdByNick(string $nickname): ?int;

    public function findNicknameById(int $id): ?string;

    /** @param list<int> $ids
     * @return array<int, string> nicknames keyed by account ID
     */
    public function findNicknamesByIds(array $ids): array;

    public function findAccountByNick(string $nickname): ?NickAccountData;

    /**
     * @param list<string> $nicknames
     *
     * @return array<string, NickAccountData> accounts keyed by lowercase nickname
     */
    public function findAccountsByNicks(array $nicknames): array;

    public function findAccountById(int $id): ?NickAccountData;

    public function getLanguage(int $id): string;
}
