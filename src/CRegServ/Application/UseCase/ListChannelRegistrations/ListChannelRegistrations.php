<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ListChannelRegistrations;

use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;

final readonly class ListChannelRegistrations
{
    public function __construct(
        public ChannelRegistrationStatus $status,
    ) {}
}
