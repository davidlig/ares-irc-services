<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGeoip;

use DateTimeImmutable;

final readonly class GeoipCountryListEntry
{
    public function __construct(
        public string $countryCode,
        public string $reason,
        public string $registeredByNick,
        public DateTimeImmutable $registeredAt,
    ) {}
}
