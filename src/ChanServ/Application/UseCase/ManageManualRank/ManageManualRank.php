<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageManualRank;

final readonly class ManageManualRank
{
    public function __construct(
        public string $channelName,
        public string $targetNickname,
        public ?int $actorAccountId,
        public bool $founderOverride,
        public ManualRankOperation $operation,
    ) {}
}
