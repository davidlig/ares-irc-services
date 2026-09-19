<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ResolveSetting;

final readonly class ResolveChannelSetting
{
    public function __construct(
        public string $channelName,
        public int $actorAccountId,
        public bool $founderEquivalent,
        public string $option,
    ) {}
}
