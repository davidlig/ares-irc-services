<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Port\In\ChanServEvent;

interface ChanServEventPublisher
{
    public function publish(ChanServEvent $event): void;
}
