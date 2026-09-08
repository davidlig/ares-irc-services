<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

final readonly class RawDatabaseMutationResult
{
    private function __construct(
        public bool $successful,
        public ?RawDatabaseMutationFailure $failure = null,
        public ?string $recordType = null,
        public ?string $recordPath = null,
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(
        RawDatabaseMutationFailure $failure,
        ?string $recordType = null,
        ?string $recordPath = null,
    ): self {
        return new self(false, $failure, $recordType, $recordPath);
    }
}
