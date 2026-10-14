<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\GetChannelRegistrationStatus;

final readonly class GetChannelRegistrationStatus
{
    public function __construct(public string $channelName) {}
}
