<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAkick;

final readonly class ManageChannelAkickResult
{
    /** @param list<ChannelAkickEntryView> $entries */
    public function __construct(
        public ManageChannelAkickOutcome $outcome,
        public array $entries = [],
        public ?string $mask = null,
        public ?string $reason = null,
        public ?string $protectedNickname = null,
    ) {}
}
