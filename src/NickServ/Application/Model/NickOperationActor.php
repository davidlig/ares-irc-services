<?php

declare(strict_types=1);

namespace App\NickServ\Application\Model;

final readonly class NickOperationActor
{
    public function __construct(
        public string $nickname,
        public ?int $accountId,
        public string $uid,
        public string $serverSid,
        public string $host,
        public string $ip,
    ) {}
}
