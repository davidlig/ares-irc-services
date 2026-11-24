<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Kill;

/** Typed intention to disconnect a connected user through OperServ. */
final readonly class KillNetworkUser
{
    public function __construct(
        public string $actorNickname,
        public string $serviceNickname,
        public string $targetNickname,
        public string $reason,
        public \DateTimeImmutable $occurredAt,
    ) {}
}
