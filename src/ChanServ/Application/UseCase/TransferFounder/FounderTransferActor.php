<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

final readonly class FounderTransferActor
{
    public function __construct(
        public string $nickname,
        public ?int $registeredNickId,
        public string $ipAddress,
        public string $hostmask,
    ) {}
}
