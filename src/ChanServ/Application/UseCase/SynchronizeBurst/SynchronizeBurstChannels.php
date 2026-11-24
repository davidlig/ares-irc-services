<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SynchronizeBurst;

interface SynchronizeBurstChannels
{
    public function handle(\DateTimeImmutable $now): void;
}
