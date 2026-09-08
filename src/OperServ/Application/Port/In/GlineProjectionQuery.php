<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In;

interface GlineProjectionQuery
{
    /** @return list<GlineProjection> */
    public function active(): array;
}
