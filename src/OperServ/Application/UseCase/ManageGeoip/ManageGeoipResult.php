<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGeoip;

final readonly class ManageGeoipResult
{
    /** @param list<GeoipCountryListEntry> $entries */
    public function __construct(
        public ManageGeoipOutcome $outcome,
        public ?string $countryCode = null,
        public ?string $reason = null,
        public array $entries = [],
    ) {}
}
