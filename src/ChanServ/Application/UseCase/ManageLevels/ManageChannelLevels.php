<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLevels;

final readonly class ManageChannelLevels
{
    /** @param list<string> $availableKeys */
    public function __construct(
        public string $channelName,
        public ?int $actorNickId,
        public bool $founderEquivalent,
        public ManageChannelLevelsAction $action,
        public array $availableKeys,
        public ?string $levelKey = null,
        public ?int $value = null,
    ) {}
}
