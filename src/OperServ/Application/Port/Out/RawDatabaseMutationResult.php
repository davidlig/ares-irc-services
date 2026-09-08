<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

final readonly class RawDatabaseMutationResult
{
    /** @param array<string, scalar|null> $errorParams */
    private function __construct(
        public bool $successful,
        public ?string $errorKey = null,
        public array $errorParams = [],
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    /** @param array<string, scalar|null> $params */
    public static function failure(string $key, array $params = []): self
    {
        return new self(false, $key, $params);
    }
}
