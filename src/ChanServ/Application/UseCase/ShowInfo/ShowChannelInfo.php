<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ShowInfo;

final readonly class ShowChannelInfo
{
    public function __construct(
        public string $channelName,
        public ?int $requesterAccountId = null,
        public bool $requesterIsIdentified = false,
        public bool $requesterIsOper = false,
    ) {}
}
