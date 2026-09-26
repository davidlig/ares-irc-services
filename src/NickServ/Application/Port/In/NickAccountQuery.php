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

    public function findAccountById(int $id): ?NickAccountData;

    public function getLanguage(int $id): string;
}
