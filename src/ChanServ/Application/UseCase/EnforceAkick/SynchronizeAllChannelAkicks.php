<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

final readonly class SynchronizeAllChannelAkicks
{
    public function __construct(public \DateTimeImmutable $now) {}
}
