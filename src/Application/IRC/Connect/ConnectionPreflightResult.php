<?php

declare(strict_types=1);

namespace App\Application\IRC\Connect;

final readonly class ConnectionPreflightResult
{
    public function __construct(
        public bool $ready,
        public ?string $message = null,
    ) {}
}
