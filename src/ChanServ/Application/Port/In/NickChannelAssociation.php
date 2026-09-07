<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

final readonly class NickChannelAssociation
{
    public function __construct(
        public string $name,
        /** @var 'founder'|'successor'|'access' */
        public string $type,
        public ?int $level = null,
    ) {}
}
