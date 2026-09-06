<?php

declare(strict_types=1);

namespace App\NickServ\Domain\Event;

final readonly class NickVhostChangedEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public ?string $vhost,
    ) {}
}
