<?php

declare(strict_types=1);

namespace App\Application\IRC\Connect;

interface ProtocolConnectionPreflightInterface
{
    public function prepare(string $protocol): ConnectionPreflightResult;
}
