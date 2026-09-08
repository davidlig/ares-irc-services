<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;

interface EnforceChannelAkickHandlerInterface
{
    public function handle(EnforceChannelAkick $command): int;

    /** @param list<ChannelEntryMember> $members */
    public function handleKnownChannel(RegisteredChannel $channel, array $members, DateTimeImmutable $now): int;
}
