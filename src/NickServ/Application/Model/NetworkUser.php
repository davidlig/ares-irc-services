<?php

declare(strict_types=1);

namespace App\NickServ\Application\Model;

final readonly class NetworkUser
{
    public function __construct(
        public string $uid,
        public string $nick,
        public string $ident,
        public string $hostname,
        public string $cloakedHost,
        public string $ipBase64,
        public bool $isIdentified = false,
        public bool $isOper = false,
        public string $serverSid = '',
        public string $displayHost = '',
        public string $modes = '',
    ) {}
}
