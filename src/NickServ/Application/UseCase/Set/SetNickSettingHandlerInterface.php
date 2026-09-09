<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Set;

interface SetNickSettingHandlerInterface
{
    public function handle(SetNickSetting $input): SetNickSettingResult;
}
