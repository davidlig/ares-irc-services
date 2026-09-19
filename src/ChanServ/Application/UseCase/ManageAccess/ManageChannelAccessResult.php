<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAccess;

final readonly class ManageChannelAccessResult
{
    /** @param list<ChannelAccessEntryView> $entries */
    public function __construct(
        public ManageChannelAccessOutcome $outcome,
        public array $entries = [],
        public ?string $targetNickname = null,
        public ?int $level = null,
    ) {}
}
