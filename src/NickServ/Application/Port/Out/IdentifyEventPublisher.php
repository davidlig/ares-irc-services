<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;

interface IdentifyEventPublisher
{
    public function publish(NickIdentifiedEvent|NickPasswordHashAvailable $event): void;
}
