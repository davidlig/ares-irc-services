<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

final readonly class RawCommandExecutionResult
{
    private function __construct(
        public RawCommandExecutionOutcome $outcome,
        public ?string $operation = null,
        public ?string $resourceType = null,
        public ?string $resourceIdentifier = null,
    ) {}

    public static function executed(string $operation, bool $intercepted): self
    {
        return new self($intercepted ? RawCommandExecutionOutcome::Intercepted : RawCommandExecutionOutcome::Sent, $operation);
    }

    public static function rejected(
        RawCommandExecutionOutcome $outcome,
        ?string $resourceType = null,
        ?string $resourceIdentifier = null,
    ): self {
        return new self(
            $outcome,
            resourceType: $resourceType,
            resourceIdentifier: $resourceIdentifier,
        );
    }
}
