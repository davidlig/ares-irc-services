<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageEmailVerification;

use DateTimeImmutable;

final readonly class ManageEmailVerification
{
    public function __construct(
        public EmailVerificationAction $action,
        public string $actor,
        public ?int $actorAccountId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
