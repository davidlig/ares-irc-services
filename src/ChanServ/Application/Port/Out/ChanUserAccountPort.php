<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChanAccountView;

interface ChanUserAccountPort
{
    public function findAccountByNick(string $nickname): ?ChanAccountView;

    public function findAccountById(int $id): ?ChanAccountView;

    public function findIdByNick(string $nickname): ?int;

    public function findNicknameById(int $id): ?string;
}
