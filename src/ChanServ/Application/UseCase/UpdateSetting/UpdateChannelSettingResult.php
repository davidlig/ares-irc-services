<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UpdateSetting;

final readonly class UpdateChannelSettingResult
{
    public function __construct(
        public UpdateChannelSettingOutcome $outcome,
        public ?string $targetNickname = null,
    ) {}
}
