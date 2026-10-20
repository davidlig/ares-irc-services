<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Application\Port\Out\GeoipCountryEntry;
use App\OperServ\Application\Port\Out\GeoipCountryRepository;
use App\OperServ\Domain\Entity\GeoipCountry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

use function array_filter;
use function array_map;
use function array_values;

/** Final Doctrine adapter for the OperServ country gate persistence. */
final readonly class DoctrineGeoipCountryRepository implements GeoipCountryRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByCode(string $countryCode): ?GeoipCountryEntry
    {
        $country = $this->entityManager
            ->getRepository(GeoipCountry::class)
            ->findOneBy(['countryCode' => GeoipCountry::normalizeCode($countryCode)]);

        return $country instanceof GeoipCountry ? $this->entry($country) : null;
    }

    public function findAll(): array
    {
        /** @var list<mixed> $countries */
        $countries = $this->entityManager->getRepository(GeoipCountry::class)->findBy([], ['countryCode' => 'ASC']);

        return $this->entries($countries);
    }

    public function save(string $countryCode, string $reason, string $registeredByNick, DateTimeImmutable $registeredAt): void
    {
        $this->entityManager->persist(GeoipCountry::create($registeredAt, $countryCode, $reason, $registeredByNick));
        $this->entityManager->flush();
    }

    public function remove(GeoipCountryEntry $entry): void
    {
        $country = null !== $entry->id
            ? $this->entityManager->find(GeoipCountry::class, $entry->id)
            : $this->entityManager->getRepository(GeoipCountry::class)->findOneBy(['countryCode' => $entry->countryCode]);
        if (!$country instanceof GeoipCountry) {
            return;
        }

        $this->entityManager->remove($country);
        $this->entityManager->flush();
    }

    private function entry(GeoipCountry $country): GeoipCountryEntry
    {
        $id = $country->getId();
        if (0 >= $id) {
            throw new LogicException('Persisted country gate entry must have an identifier.');
        }

        return new GeoipCountryEntry(
            $country->getCountryCode(),
            $country->getReason(),
            $country->getRegisteredByNick(),
            $country->getRegisteredAt(),
            $id,
        );
    }

    /** @param list<mixed> $countries
     * @return list<GeoipCountryEntry>
     */
    private function entries(array $countries): array
    {
        return array_values(array_map(
            $this->entry(...),
            array_filter($countries, static fn (mixed $country): bool => $country instanceof GeoipCountry),
        ));
    }
}
