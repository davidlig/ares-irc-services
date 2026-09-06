<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\InMemory;

use App\Application\NickServ\PendingVerificationRegistry;
use App\NickServ\Application\Port\Out\RegistrationVerificationStore;
use DateTimeImmutable;

final readonly class PendingRegistrationVerificationStore implements RegistrationVerificationStore
{
    public function __construct(private PendingVerificationRegistry $registry) {}

    public function store(string $nickname, string $token, DateTimeImmutable $expiresAt): void
    {
        $this->registry->store($nickname, $token, $expiresAt);
    }
}
