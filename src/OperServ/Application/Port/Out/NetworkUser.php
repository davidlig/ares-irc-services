<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

final readonly class NetworkUser
{
    public function __construct(
        public string $uid,
        public string $nickname,
        public string $ident,
        public string $hostname,
        public string $ipBase64,
        public bool $identified,
        public bool $ircOperator,
    ) {}
}
