<?php

declare(strict_types=1);

namespace App\NickServ\Application\PublishedEvent;

use App\NickServ\Application\Port\In\NickServEvent;
use stdClass;

final readonly class NickBurstBatchStartedEvent implements NickServEvent
{
    /** @param list<int> $nickIds */
    public function __construct(public array $nickIds, public object $token = new stdClass()) {}
}
