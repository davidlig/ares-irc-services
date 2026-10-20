<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

use DateTimeImmutable;

interface GeoipCountryRepository
{
    public function findByCode(string $countryCode): ?GeoipCountryEntry;

    /** @return list<GeoipCountryEntry> */
    public function findAll(): array;

    public function save(string $countryCode, string $reason, string $registeredByNick, DateTimeImmutable $registeredAt): void;

    public function remove(GeoipCountryEntry $entry): void;
}
