<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface OperatorAccountLookup
{
    public function findIdByNickname(string $nickname): ?int;

    public function findByNickname(string $nickname): ?OperatorAccountData;

    public function findNicknameById(int $id): ?string;
}
