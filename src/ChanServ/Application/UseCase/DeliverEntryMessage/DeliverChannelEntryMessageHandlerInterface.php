<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\DeliverEntryMessage;

interface DeliverChannelEntryMessageHandlerInterface
{
    public function handle(DeliverChannelEntryMessage $command): void;
}
