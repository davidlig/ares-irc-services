<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UpdateSetting;

use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;

final readonly class UpdateChannelSetting
{
    public function __construct(
        public RegisteredChannel $channel,
        public ChannelSetting $setting,
        public string $value,
        public string $actorNickname,
        public ?int $actorAccountId,
        public string $actorIp,
        public string $actorHost,
        public DateTimeImmutable $occurredAt,
    ) {}
}
