<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

final readonly class GeoipCountryEntry
{
    public function __construct(
        public string $countryCode,
        public string $reason,
        public string $registeredByNick,
        public DateTimeImmutable $registeredAt,
        public ?int $id = null,
    ) {}
}
