<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ConfigureMlock;

use App\ChanServ\Domain\ValueObject\ChannelModeLock;

final readonly class ConfigureChannelMlockResult
{
    public function __construct(public ChannelModeLock $modeLock) {}
}
