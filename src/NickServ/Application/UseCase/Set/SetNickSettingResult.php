<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

final readonly class SetNickSettingResult
{
    public function __construct(
        public SetNickSettingOutcome $outcome,
        public SetNickSettingOption $option,
        public string $targetNickname,
        public ?string $value = null,
        public ?string $currentEmail = null,
        public ?string $newEmail = null,
    ) {}
}
