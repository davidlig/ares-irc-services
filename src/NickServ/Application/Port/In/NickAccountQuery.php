<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

interface NickAccountQuery
{
    public function findIdByNick(string $nickname): ?int;

    public function findNicknameById(int $id): ?string;

    public function findAccountByNick(string $nickname): ?NickAccountData;

    public function getLanguage(int $id): string;
}
