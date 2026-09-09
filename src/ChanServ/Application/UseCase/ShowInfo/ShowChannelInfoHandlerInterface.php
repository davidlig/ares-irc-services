<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ShowInfo;

interface ShowChannelInfoHandlerInterface
{
    public function handle(ShowChannelInfo $query): ?ChannelInfoView;
}
