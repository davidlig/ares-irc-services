<?php

declare(strict_types=1);

namespace App\Irc\Application\PublishedEvent;

final readonly class UserNicknameChangedEvent
{
    public function __construct(
        public string $uid,
        public string $oldNickname,
        public string $newNickname,
    ) {}
}
