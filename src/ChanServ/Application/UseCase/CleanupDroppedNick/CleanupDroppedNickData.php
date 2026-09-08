<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\CleanupDroppedNick;

final readonly class CleanupDroppedNickData
{
    public function __construct(public int $nickId) {}
}
