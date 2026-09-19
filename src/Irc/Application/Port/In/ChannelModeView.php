<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

final readonly class ChannelModeView
{
    public function __construct(
        public string $name,
        public string $modes,
    ) {}
}
