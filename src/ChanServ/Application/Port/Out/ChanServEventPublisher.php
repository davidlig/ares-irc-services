<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChanServEventPublisher
{
    public function publish(object $event): void;
}
