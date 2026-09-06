<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\NickServ\Application\Port\Out\RegistrationVerificationStore;
use App\NickServ\Application\Port\Out\VerificationTokenConsumer;
use DateTimeImmutable;

final readonly class PendingRegistrationVerificationStore implements RegistrationVerificationStore, VerificationTokenConsumer
{
    public function __construct(private PendingVerificationRegistry $registry) {}

    public function store(string $nickname, string $token, DateTimeImmutable $expiresAt): void
    {
        $this->registry->store($nickname, $token, $expiresAt);
    }

    public function consume(string $nickname, string $token): bool
    {
        return $this->registry->consume($nickname, $token);
    }
}
