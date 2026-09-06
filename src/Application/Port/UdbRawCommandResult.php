<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Result of an intercepted OperServ RAW UDB mutation.
 *
 * auditLine carries the operator's line with secret values (passwords,
 * challenges, encryption keys) already redacted for logs and audit trails.
 */
final readonly class UdbRawCommandResult
{
    /**
     * @param array<string, mixed> $errorParams
     */
    public function __construct(
        public bool $success,
        public ?string $errorKey = null,
        public array $errorParams = [],
        public ?string $auditLine = null,
    ) {}

    public static function success(string $auditLine): self
    {
        return new self(true, auditLine: $auditLine);
    }

    /**
     * @param array<string, mixed> $errorParams
     */
    public static function error(string $errorKey, array $errorParams = []): self
    {
        return new self(false, errorKey: $errorKey, errorParams: $errorParams);
    }
}
