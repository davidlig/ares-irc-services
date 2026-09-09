<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

final readonly class RawCommandInterception
{
    private function __construct(
        public RawCommandInterceptionOutcome $outcome,
        public ?string $operation = null,
        public ?RawCommandInterceptionFailure $failure = null,
        public ?string $resourceType = null,
        public ?string $resourceIdentifier = null,
    ) {}

    public static function notHandled(): self
    {
        return new self(RawCommandInterceptionOutcome::NotHandled);
    }

    public static function executed(string $operation): self
    {
        return new self(RawCommandInterceptionOutcome::Executed, operation: $operation);
    }

    public static function rejected(
        RawCommandInterceptionFailure $failure,
        ?string $resourceType = null,
        ?string $resourceIdentifier = null,
    ): self {
        return new self(
            RawCommandInterceptionOutcome::Rejected,
            failure: $failure,
            resourceType: $resourceType,
            resourceIdentifier: $resourceIdentifier,
        );
    }
}
