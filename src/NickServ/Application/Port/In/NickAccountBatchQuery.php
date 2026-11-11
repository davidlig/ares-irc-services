<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

interface NickAccountBatchQuery
{
    /**
     * @param list<string> $nicknames
     *
     * @return array<string, NickAccountData> keyed by lowercase nickname
     */
    public function findAccountsByNicks(array $nicknames): array;
}
