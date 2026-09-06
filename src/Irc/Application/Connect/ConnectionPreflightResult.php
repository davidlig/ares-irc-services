<?php

declare(strict_types=1);

namespace App\Irc\Application\Connect;

final readonly class ConnectionPreflightResult
{
    public function __construct(
        public bool $ready,
        public ?string $message = null,
    ) {}
}
