<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\UnbanUser;

final readonly class UnbanUserResult
{
    public function __construct(public int $channelCreationTimestamp) {}
}
