<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\Port\In\NickServEvent;

interface RecoverEventPublisher
{
    public function publish(NickServEvent $event): void;
}
