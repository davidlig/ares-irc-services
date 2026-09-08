<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Kill;

/** Semantic outcome; IRC wording is deliberately left to the inbound adapter. */
final readonly class KillNetworkUserResult
{
    private function __construct(
        public KillNetworkUserOutcome $outcome,
        public string $targetNickname,
        public string $reason,
    ) {}

    public static function killed(string $targetNickname, string $reason): self
    {
        return new self(KillNetworkUserOutcome::Killed, $targetNickname, $reason);
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
