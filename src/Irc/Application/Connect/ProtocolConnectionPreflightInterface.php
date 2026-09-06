<?php

declare(strict_types=1);

namespace App\Irc\Application\Connect;

interface ProtocolConnectionPreflightInterface
{
    public function prepare(): ConnectionPreflightResult;
}
