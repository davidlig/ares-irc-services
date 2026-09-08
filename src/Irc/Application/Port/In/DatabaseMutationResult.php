<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

final readonly class DatabaseMutationResult
{
    private function __construct(
        public bool $success,
        public ?DatabaseMutationFailure $failure = null,
        public ?string $recordType = null,
        public ?string $path = null,
        public ?string $reason = null,
    ) {}

    public static function success(): self
    {
        return new self(true);
    }

    public static function failure(
        DatabaseMutationFailure $failure,
        ?string $recordType = null,
        ?string $path = null,
        ?string $reason = null,
    ): self {
        return new self(false, $failure, $recordType, $path, $reason);
    }
}
