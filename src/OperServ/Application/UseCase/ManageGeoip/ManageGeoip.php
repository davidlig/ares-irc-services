<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGeoip;

use DateTimeImmutable;

final readonly class ManageGeoip
{
    public function __construct(
        public GeoipAction $action,
        public string $actor,
        public ?int $actorAccountId,
        public DateTimeImmutable $occurredAt,
        public ?string $countryCode = null,
        public ?string $reason = null,
    ) {}
}
