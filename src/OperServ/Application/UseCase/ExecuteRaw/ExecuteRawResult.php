<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ExecuteRaw;

final readonly class ExecuteRawResult
{
    private function __construct(
        public RawDatabaseExecutionOutcome $outcome,
        public ?string $operation = null,
        public ?string $recordType = null,
        public ?string $recordPath = null,
    ) {}

    public static function executed(string $operation, bool $database = false): self
    {
        return new self($database ? RawDatabaseExecutionOutcome::DatabaseExecuted : RawDatabaseExecutionOutcome::Executed, $operation);
    }

    public static function rejected(
        RawDatabaseExecutionOutcome $outcome,
        ?string $recordType = null,
        ?string $recordPath = null,
    ): self {
        return new self($outcome, recordType: $recordType, recordPath: $recordPath);
    }
}
