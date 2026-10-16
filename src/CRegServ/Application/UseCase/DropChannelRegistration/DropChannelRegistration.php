<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

final readonly class DropChannelRegistration
{
    /**
     * No operator or timestamp here: nothing in the record stores who dropped it, and the
     * CReG handlers do not log, so both would be dead parameters.
     */
    public function __construct(
        public string $channelName,
    ) {}
}
