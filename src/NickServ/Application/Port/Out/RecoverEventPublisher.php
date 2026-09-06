<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface RecoverEventPublisher
{
    public function publish(object $event): void;
}
