<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

interface NickProjectionQuery
{
    /** @return list<NickProjection> */
    public function all(): array;

    public function findById(int $id): ?NickProjection;
}
