<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineGeoipCountryRepository;
use App\OperServ\Application\Port\Out\GeoipCountryEntry;
use App\OperServ\Domain\Entity\GeoipCountry;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

#[CoversClass(DoctrineGeoipCountryRepository::class)]
#[CoversClass(GeoipCountryEntry::class)]
final class DoctrineGeoipCountryRepositoryTest extends TestCase
{
    #[Test]
    public function findsByCodeAndReturnsNullForUnexpectedValues(): void
    {
        $country = $this->country(7);
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::exactly(2))->method('findOneBy')
            ->with(self::logicalOr(['countryCode' => 'ES'], ['countryCode' => 'ES']))
            ->willReturnOnConsecutiveCalls($country, new stdClass());
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $adapter = new DoctrineGeoipCountryRepository($entityManager);

        $entry = $adapter->findByCode('es');

        self::assertNotNull($entry);
        self::assertSame(7, $entry->id);
        self::assertSame('ES', $entry->countryCode);
        self::assertSame('reason', $entry->reason);
        self::assertSame('Oper', $entry->registeredByNick);
        self::assertNull($adapter->findByCode('ES'));
    }

    #[Test]
    public function findsAllInExpectedOrderAndFiltersUnexpectedValues(): void
    {
        $country = $this->country(7);
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::once())->method('findBy')
            ->with([], ['countryCode' => 'ASC'])
            ->willReturn([$country, new stdClass()]);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);

        $entries = new DoctrineGeoipCountryRepository($entityManager)->findAll();

        self::assertCount(1, $entries);
        self::assertSame('ES', $entries[0]->countryCode);
    }

    #[Test]
    public function persistsNewCountry(): void
    {
        $registeredAt = new DateTimeImmutable('2026-10-20T10:00:00+00:00');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(
            static fn (GeoipCountry $country): bool => 'ES' === $country->getCountryCode()
                && 'reason' === $country->getReason()
                && 'Oper' === $country->getRegisteredByNick()
                && $registeredAt === $country->getRegisteredAt(),
        ));
        $entityManager->expects(self::once())->method('flush');

        new DoctrineGeoipCountryRepository($entityManager)->save('ES', 'reason', 'Oper', $registeredAt);
    }

    #[Test]
    public function removesByIdentifierAndIgnoresMissingEntry(): void
    {
        $country = $this->country(7);
        $entry = $this->entry(7);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('find')
            ->with(GeoipCountry::class, 7)
            ->willReturnOnConsecutiveCalls(null, $country);
        $entityManager->expects(self::once())->method('remove')->with($country);
        $entityManager->expects(self::once())->method('flush');
        $adapter = new DoctrineGeoipCountryRepository($entityManager);

        $adapter->remove($entry);
        $adapter->remove($entry);
    }

    #[Test]
    public function removesLegacyEntryWithoutIdentifierByCode(): void
    {
        $country = $this->country(7);
        $objectRepository = $this->createMock(EntityRepository::class);
        $objectRepository->expects(self::once())->method('findOneBy')
            ->with(['countryCode' => 'ES'])
            ->willReturn($country);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);
        $entityManager->expects(self::once())->method('remove')->with($country);
        $entityManager->expects(self::once())->method('flush');

        new DoctrineGeoipCountryRepository($entityManager)->remove($this->entry(null));
    }

    #[Test]
    public function rejectsMappedCountryWithoutPositiveIdentifier(): void
    {
        $country = $this->country(0);
        $objectRepository = $this->createStub(EntityRepository::class);
        $objectRepository->method('findOneBy')->willReturn($country);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectRepository);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Persisted country gate entry must have an identifier.');

        new DoctrineGeoipCountryRepository($entityManager)->findByCode('ES');
    }

    private function country(int $id): GeoipCountry
    {
        $country = GeoipCountry::create(
            new DateTimeImmutable('2026-10-20T10:00:00+00:00'),
            'ES',
            'reason',
            'Oper',
        );
        new ReflectionProperty(GeoipCountry::class, 'id')->setValue($country, $id);

        return $country;
    }

    private function entry(?int $id): GeoipCountryEntry
    {
        return new GeoipCountryEntry(
            'ES',
            'reason',
            'Oper',
            new DateTimeImmutable('2026-10-20T10:00:00+00:00'),
            $id,
        );
    }
}
