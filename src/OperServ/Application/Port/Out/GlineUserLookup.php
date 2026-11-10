<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface GlineUserLookup
{
    public function findByNickname(string $nickname): ?GlineUser;

    public function findNicknameByAccountId(int $accountId): ?string;

    /** @param list<int> $accountIds
     * @return array<int, string> Nicknames keyed by account ID
     */
    public function findNicknamesByAccountIds(array $accountIds): array;
}
