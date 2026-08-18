<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

final readonly class NickPasswordProvidedEvent
{
    public function __construct(
        public ?int $nickId,
        public string $nickname,
        public string $plaintextPassword,
    ) {}
}
