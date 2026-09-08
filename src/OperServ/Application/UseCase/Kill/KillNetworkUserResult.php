<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Kill;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;

/** Semantic outcome; IRC wording is deliberately left to the inbound adapter. */
final readonly class KillNetworkUserResult
{
    private function __construct(
        public KillNetworkUserOutcome $outcome,
        public string $targetNickname,
        public string $reason,
        public ?CommandAuditRecord $auditRecord = null,
    ) {}

    public static function killed(string $targetNickname, string $reason, CommandAuditRecord $auditRecord): self
    {
        return new self(KillNetworkUserOutcome::Killed, $targetNickname, $reason, $auditRecord);
    }

    public static function notOnline(string $targetNickname): self
    {
        return new self(KillNetworkUserOutcome::NotOnline, $targetNickname, '');
    }

    public static function protectedRoot(string $targetNickname): self
    {
        return new self(KillNetworkUserOutcome::ProtectedRoot, $targetNickname, '');
    }

    public static function protectedIrcOperator(string $targetNickname): self
    {
        return new self(KillNetworkUserOutcome::ProtectedIrcOperator, $targetNickname, '');
    }

    public static function networkUnavailable(string $targetNickname): self
    {
        return new self(KillNetworkUserOutcome::NetworkUnavailable, $targetNickname, '');
    }
}
