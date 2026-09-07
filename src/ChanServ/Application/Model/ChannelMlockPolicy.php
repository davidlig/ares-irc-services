<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Domain\ValueObject\ChannelModeLock;

final readonly class ChannelMlockPolicy
{
    public function __construct(
        public string $name,
        public bool $blocked,
        public ChannelModeLock $modeLock,
    ) {}
}
