<?php

declare(strict_types=1);

namespace App\NickServ\Application\PublishedEvent;

use App\NickServ\Application\Port\In\NickServEvent;

final readonly class NickBurstBatchFinishedEvent implements NickServEvent
{
    public function __construct(public object $token) {}
}
