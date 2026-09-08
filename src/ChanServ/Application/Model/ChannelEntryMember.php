<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

final readonly class ChannelEntryMember
{
    public function __construct(
        public string $uid,
        public string $nickname,
        public string $userMask,
        public bool $identified,
        public bool $operator,
        public bool $service,
    ) {}
}
