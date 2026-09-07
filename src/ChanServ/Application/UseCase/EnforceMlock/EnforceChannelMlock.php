<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceMlock;

final readonly class EnforceChannelMlock
{
    public function __construct(
        public string $channelName,
        public MlockEnforcementTrigger $trigger,
    ) {}
}
