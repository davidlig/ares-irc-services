<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

interface TransferChannelFounderHandlerInterface
{
    public function handle(TransferChannelFounder $command): TransferChannelFounderResult;
}
