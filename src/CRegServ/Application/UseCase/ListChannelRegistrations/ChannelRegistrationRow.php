<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ListChannelRegistrations;

use DateTimeImmutable;

/**
 * One line of the oper work queue, carrying only what the presentation needs so the
 * command never touches a mutable entity.
 */
final readonly class ChannelRegistrationRow
{
    public function __construct(
        public string $channel,
        public string $requesterNick,
        public string $description,
        public DateTimeImmutable $createdAt,
        public ?string $deniedReason,
        public ?DateTimeImmutable $deniedAt,
    ) {}
}
