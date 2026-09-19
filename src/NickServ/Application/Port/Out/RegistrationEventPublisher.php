<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;

interface RegistrationEventPublisher
{
    public function publish(NickPasswordHashAvailable $event): void;
}
