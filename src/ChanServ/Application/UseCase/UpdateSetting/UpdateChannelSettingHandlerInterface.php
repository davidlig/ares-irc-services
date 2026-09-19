<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UpdateSetting;

interface UpdateChannelSettingHandlerInterface
{
    public function handle(UpdateChannelSetting $command): UpdateChannelSettingResult;
}
