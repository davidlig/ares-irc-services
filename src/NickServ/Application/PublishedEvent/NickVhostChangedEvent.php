<?php

declare(strict_types=1);

namespace App\NickServ\Application\PublishedEvent;

use App\NickServ\Application\Port\In\NickServEvent;

final readonly class NickVhostChangedEvent implements NickServEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public ?string $vhost,
    ) {}
}
