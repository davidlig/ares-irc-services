<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Suspend;

use App\NickServ\Application\Model\NickOperationActor;

final readonly class SuspendNick
{
    public function __construct(
        public NickOperationActor $actor,
        public string $targetNickname,
        public string $duration,
        public string $reason,
    ) {}
}
