<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ExecuteRaw;

final readonly class ExecuteRawResult
{
    /** @param array<string, scalar|null> $parameters */
    private function __construct(
        public RawDatabaseExecutionOutcome $outcome,
        public ?string $operation = null,
        public array $parameters = [],
    ) {}

    public static function executed(string $operation, bool $database = false): self
    {
        return new self($database ? RawDatabaseExecutionOutcome::DatabaseExecuted : RawDatabaseExecutionOutcome::Executed, $operation);
    }

    /** @param array<string, scalar|null> $parameters */
    public static function rejected(RawDatabaseExecutionOutcome $outcome, array $parameters = []): self
    {
        return new self($outcome, parameters: $parameters);
    }
}
