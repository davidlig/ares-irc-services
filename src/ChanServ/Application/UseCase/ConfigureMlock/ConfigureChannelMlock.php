<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ConfigureMlock;

use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use DateTimeImmutable;

final readonly class ConfigureChannelMlock
{
    public function __construct(
        public RegisteredChannel $channel,
        public ChannelModeLock $modeLock,
        public string $actorNickname,
        public DateTimeImmutable $occurredAt,
    ) {}
}
