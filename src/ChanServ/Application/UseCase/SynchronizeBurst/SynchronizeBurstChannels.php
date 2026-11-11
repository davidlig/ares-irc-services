<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SynchronizeBurst;

use DateTimeImmutable;

interface SynchronizeBurstChannels
{
    public function handle(DateTimeImmutable $now): void;
}
