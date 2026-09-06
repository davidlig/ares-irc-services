<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface IdentifyEventPublisher
{
    public function publish(object $event): void;
}
