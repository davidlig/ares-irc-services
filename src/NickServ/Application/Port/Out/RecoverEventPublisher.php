<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\Event\NickRecoveredEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;

interface RecoverEventPublisher
{
    public function publish(NickPasswordHashAvailable|NickRecoveredEvent $event): void;
}
