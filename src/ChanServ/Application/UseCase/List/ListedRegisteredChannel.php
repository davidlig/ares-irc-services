<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\List;

use App\ChanServ\Domain\ValueObject\ChannelStatus;
use DateTimeImmutable;

final readonly class ListedRegisteredChannel
{
    public function __construct(
        public string $channelName,
        public ?string $founderName,
        public ?DateTimeImmutable $registeredAt,
        public ?DateTimeImmutable $lastUsedAt,
        public ChannelStatus $status,
    ) {}
}
