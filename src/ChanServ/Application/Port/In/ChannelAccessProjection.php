<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

final readonly class ChannelAccessProjection
{
    public function __construct(public int $nickId, public int $level) {}
}
