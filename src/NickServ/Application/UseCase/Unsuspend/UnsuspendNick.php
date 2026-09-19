<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Unsuspend;

use App\NickServ\Application\Model\NickOperationActor;

final readonly class UnsuspendNick
{
    public function __construct(
        public NickOperationActor $actor,
        public string $targetNickname,
    ) {}
}
