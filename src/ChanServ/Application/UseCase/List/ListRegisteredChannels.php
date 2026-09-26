<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\List;

final readonly class ListRegisteredChannels
{
    public function __construct(
        public string $pattern,
        public int $page = 1,
    ) {}
}
