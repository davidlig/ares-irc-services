<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ListChannelRegistrations;

final readonly class ListChannelRegistrationsResult
{
    /**
     * @param ChannelRegistrationRow[] $rows newest first
     */
    public function __construct(
        public array $rows,
    ) {}
}
