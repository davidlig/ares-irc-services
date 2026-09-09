<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ResolveSetting;

use App\ChanServ\Domain\Entity\RegisteredChannel;

interface ResolveChannelSettingHandlerInterface
{
    public function handle(ResolveChannelSetting $query): RegisteredChannel;
}
