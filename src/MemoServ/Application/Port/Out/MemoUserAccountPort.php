<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Port\Out;

use App\MemoServ\Application\Model\MemoAccountView;

interface MemoUserAccountPort
{
    public function findAccountByNick(string $nickname): ?MemoAccountView;

    public function findNicknameById(int $id): ?string;

    /** @param list<int> $ids
     * @return array<int, string> nicknames keyed by account ID
     */
    public function findNicknamesByIds(array $ids): array;

    public function getLanguage(int $id): string;
}
