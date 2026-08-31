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
    public function __construct(
        public readonly bool $success,
        public readonly ?string $errorKey = null,
        public readonly array $errorParams = [],
        public readonly ?string $auditLine = null,
    ) {}

    public static function success(string $auditLine): self
    {
        return new self(true, auditLine: $auditLine);
    }

    public static function error(string $errorKey, array $errorParams = []): self
    {
        return new self(false, errorKey: $errorKey, errorParams: $errorParams);
    }
}
