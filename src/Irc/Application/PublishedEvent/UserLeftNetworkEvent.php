<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class UserLeftNetworkEvent
{
    public function __construct(
        public string $uid,
        public string $nickname,
        public string $reason,
        public string $ident = '',
        public string $displayHost = '',
        public string $hostname = '',
        public string $ipBase64 = '',
    ) {}
}
