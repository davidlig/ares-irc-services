<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageAccess;

final readonly class ChannelAccessEntryView
{
    public function __construct(
        public string $nickname,
        public int $level,
    ) {}
}
