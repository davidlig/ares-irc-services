<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

use App\NickServ\Application\Model\NickOperationActor;
use SensitiveParameter;

final readonly class SetNickSetting
{
    public function __construct(
        public NickOperationActor $actor,
        public string $targetNickname,
        public SetNickSettingOption $option,
        #[SensitiveParameter]
        public string $value,
        public bool $operatorMode,
        public string $locale,
        public string $clientKey = '',
    ) {}
}
