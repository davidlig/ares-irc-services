<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageManualRank;

final readonly class ManageManualRankResult
{
    private function __construct(
        public ManageManualRankOutcome $outcome,
        public string $channelName,
        public string $targetNickname,
        public ?int $requiredLevel = null,
    ) {}

    public static function of(ManageManualRankOutcome $outcome, string $channelName, string $targetNickname, ?int $requiredLevel = null): self
    {
        return new self($outcome, $channelName, $targetNickname, $requiredLevel);
    }
}
