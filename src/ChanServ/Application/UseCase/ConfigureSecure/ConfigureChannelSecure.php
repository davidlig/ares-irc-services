<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ConfigureSecure;

use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class ConfigureChannelSecure
{
    public function __construct(
        public RegisteredChannel $channel,
        public bool $enabled,
    ) {}
}
