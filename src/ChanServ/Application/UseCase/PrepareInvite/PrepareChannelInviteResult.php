<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\PrepareInvite;

final readonly class PrepareChannelInviteResult
{
    public function __construct(public int $channelCreationTimestamp) {}
}
