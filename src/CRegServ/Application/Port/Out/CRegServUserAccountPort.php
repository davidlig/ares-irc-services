<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Port\Out;

use App\CRegServ\Application\Model\CRegServAccountView;

interface CRegServUserAccountPort
{
    public function findAccountByNick(string $nickname): ?CRegServAccountView;

    public function findNicknameById(int $id): ?string;

    public function getLanguage(int $id): string;
}
