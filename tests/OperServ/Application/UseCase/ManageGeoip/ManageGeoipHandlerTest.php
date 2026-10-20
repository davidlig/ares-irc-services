<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ManageGeoip;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\GeoipCountryEntry;
use App\OperServ\Application\Port\Out\GeoipCountryRepository;
use App\OperServ\Application\Port\Out\GlineNetworkActions;
use App\OperServ\Application\UseCase\ManageGeoip\GeoipAction;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoip;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipHandler;
use App\OperServ\Application\UseCase\ManageGeoip\ManageGeoipOutcome;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strtoupper;

use const DATE_ATOM;

#[CoversClass(ManageGeoipHandler::class)]
#[CoversClass(ManageGeoip::class)]
#[CoversClass(GeoipCountryEntry::class)]
final class ManageGeoipHandlerTest extends TestCase
{
    #[Test]
    public function addsKnownCountryAndPublishesTheCountryGateMask(): void
    {
        $repository = new RecordingGeoipCountryRepository();
        $network = new RecordingGeoipNetworkActions();
        $audit = new RecordingGeoipAuditRecorder();
        $now = new DateTimeImmutable('2026-10-20T10:00:00+00:00');

        $result = $this->handler($repository, $network, $audit)
            ->handle(new ManageGeoip(GeoipAction::Add, 'Oper', 44, $now, 'es', 'Not allowed in this network'));

        self::assertSame(ManageGeoipOutcome::Added, $result->outcome);
        self::assertSame('ES', $result->countryCode);
        self::assertSame('Not allowed in this network', $result->reason);
        self::assertSame('ES', $repository->saved[0][0]);
        self::assertSame('Not allowed in this network', $repository->saved[0][1]);
        self::assertSame('Oper', $repository->saved[0][2]);
        self::assertSame('2026-10-20T10:00:00+00:00', $repository->saved[0][3]->format(DATE_ATOM));
        self::assertSame(['~country:ES', 'Not allowed in this network'], $network->addedByMask[0]);
        self::assertCount(1, $audit->records);
        self::assertSame(CommandAuditCategory::OperatorAction, $audit->records[0]->category);
        self::assertSame('GEOIP ADD', $audit->records[0]->operation);
        self::assertSame('operserv.geoip', $audit->records[0]->permission);
        self::assertSame('ES', $audit->records[0]->target);
        self::assertSame('Not allowed in this network', $audit->records[0]->reason);
    }

    #[Test]
    public function rejectsAnUnknownCountryCode(): void
    {
        $repository = new RecordingGeoipCountryRepository();
        $network = new RecordingGeoipNetworkActions();
        $audit = new RecordingGeoipAuditRecorder();

        $result = $this->handler($repository, $network, $audit)
            ->handle(new ManageGeoip(GeoipAction::Add, 'Oper', 44, new DateTimeImmutable(), 'XX', 'reason'));

        self::assertSame(ManageGeoipOutcome::UnknownCountryCode, $result->outcome);
        self::assertSame('XX', $result->countryCode);
        self::assertSame([], $repository->saved);
        self::assertSame([], $network->addedByMask);
        self::assertSame([], $audit->records);
    }

    #[Test]
    public function rejectsADuplicateCountry(): void
    {
        $repository = new RecordingGeoipCountryRepository([
            new GeoipCountryEntry('ES', 'reason', 'Oper', new DateTimeImmutable('2026-10-20T10:00:00+00:00'), 3),
        ]);
        $network = new RecordingGeoipNetworkActions();
        $audit = new RecordingGeoipAuditRecorder();

        $result = $this->handler($repository, $network, $audit)
            ->handle(new ManageGeoip(GeoipAction::Add, 'Oper', 44, new DateTimeImmutable(), 'ES', 'reason'));

        self::assertSame(ManageGeoipOutcome::AlreadyExists, $result->outcome);
        self::assertSame([], $repository->saved);
        self::assertSame([], $network->addedByMask);
        self::assertSame([], $audit->records);
    }

    #[Test]
    public function rejectsAnAddWithoutReason(): void
    {
        $repository = new RecordingGeoipCountryRepository();
        $network = new RecordingGeoipNetworkActions();
        $audit = new RecordingGeoipAuditRecorder();

        $result = $this->handler($repository, $network, $audit)
            ->handle(new ManageGeoip(GeoipAction::Add, 'Oper', 44, new DateTimeImmutable(), 'ES', '   '));

        self::assertSame(ManageGeoipOutcome::InvalidRequest, $result->outcome);
        self::assertSame([], $repository->saved);
        self::assertSame([], $network->addedByMask);
        self::assertSame([], $audit->records);
    }

    #[Test]
    public function deletesAnExistingCountryAndPublishesTheRemoval(): void
    {
        $entry = new GeoipCountryEntry('ES', 'reason', 'Oper', new DateTimeImmutable('2026-10-20T10:00:00+00:00'), 5);
        $repository = new RecordingGeoipCountryRepository([$entry]);
        $network = new RecordingGeoipNetworkActions();
        $audit = new RecordingGeoipAuditRecorder();

        $result = $this->handler($repository, $network, $audit)
            ->handle(new ManageGeoip(GeoipAction::Delete, 'Oper', 44, new DateTimeImmutable(), 'es'));

        self::assertSame(ManageGeoipOutcome::Deleted, $result->outcome);
        self::assertSame('ES', $result->countryCode);
        self::assertSame([$entry], $repository->removed);
        self::assertSame(['~country:ES'], $network->removedByMask);
        self::assertSame('GEOIP DEL', $audit->records[0]->operation);
        self::assertNull($audit->records[0]->reason);
    }

    #[Test]
    public function reportsAnUnknownCountryOnDelete(): void
    {
        $repository = new RecordingGeoipCountryRepository();
        $network = new RecordingGeoipNetworkActions();
        $audit = new RecordingGeoipAuditRecorder();

        $result = $this->handler($repository, $network, $audit)
            ->handle(new ManageGeoip(GeoipAction::Delete, 'Oper', 44, new DateTimeImmutable(), 'ES'));

        self::assertSame(ManageGeoipOutcome::NotFound, $result->outcome);
        self::assertSame([], $repository->removed);
        self::assertSame([], $network->removedByMask);
        self::assertSame([], $audit->records);
    }

    #[Test]
    public function reportsAnInvalidCountryCodeOnDelete(): void
    {
        $repository = new RecordingGeoipCountryRepository();

        $result = $this->handler($repository, new RecordingGeoipNetworkActions(), new RecordingGeoipAuditRecorder())
            ->handle(new ManageGeoip(GeoipAction::Delete, 'Oper', 44, new DateTimeImmutable(), 'XX'));

        self::assertSame(ManageGeoipOutcome::UnknownCountryCode, $result->outcome);
    }

    #[Test]
    public function listsTheBlockedCountries(): void
    {
        $registeredAt = new DateTimeImmutable('2026-10-20T10:00:00+00:00');
        $repository = new RecordingGeoipCountryRepository([
            new GeoipCountryEntry('ES', 'reason', 'Oper', $registeredAt, 1),
        ]);

        $result = $this->handler($repository, new RecordingGeoipNetworkActions(), new RecordingGeoipAuditRecorder())
            ->handle(new ManageGeoip(GeoipAction::List, 'Oper', 44, new DateTimeImmutable()));

        self::assertSame(ManageGeoipOutcome::Listed, $result->outcome);
        self::assertCount(1, $result->entries);
        self::assertSame('ES', $result->entries[0]->countryCode);
        self::assertSame('reason', $result->entries[0]->reason);
        self::assertSame('Oper', $result->entries[0]->registeredByNick);
        self::assertSame($registeredAt, $result->entries[0]->registeredAt);
    }

    #[Test]
    public function reportsAnEmptyList(): void
    {
        $result = $this->handler(new RecordingGeoipCountryRepository(), new RecordingGeoipNetworkActions(), new RecordingGeoipAuditRecorder())
            ->handle(new ManageGeoip(GeoipAction::List, 'Oper', 44, new DateTimeImmutable()));

        self::assertSame(ManageGeoipOutcome::ListEmpty, $result->outcome);
    }

    #[Test]
    public function reportsAnUnknownAction(): void
    {
        $result = $this->handler(new RecordingGeoipCountryRepository(), new RecordingGeoipNetworkActions(), new RecordingGeoipAuditRecorder())
            ->handle(new ManageGeoip(GeoipAction::Unknown, 'Oper', 44, new DateTimeImmutable()));

        self::assertSame(ManageGeoipOutcome::UnknownAction, $result->outcome);
    }

    /** @return iterable<string, array{GeoipAction, ?string, ManageGeoipOutcome}> */
    public static function rejectedCountrySelectorProvider(): iterable
    {
        yield 'empty country' => [GeoipAction::Add, '', ManageGeoipOutcome::UnknownCountryCode];
        yield 'blank country' => [GeoipAction::Add, '   ', ManageGeoipOutcome::UnknownCountryCode];
        yield 'unknown country' => [GeoipAction::Add, 'XX', ManageGeoipOutcome::UnknownCountryCode];
        yield 'long country' => [GeoipAction::Add, 'ESP', ManageGeoipOutcome::UnknownCountryCode];
        yield 'missing country' => [GeoipAction::Delete, null, ManageGeoipOutcome::UnknownCountryCode];
    }

    #[DataProvider('rejectedCountrySelectorProvider')]
    public function rejectsCountrySelectorsThatAreNotCountryCodes(GeoipAction $action, ?string $countryCode, ManageGeoipOutcome $expected): void
    {
        $repository = new RecordingGeoipCountryRepository();

        $result = $this->handler($repository, new RecordingGeoipNetworkActions(), new RecordingGeoipAuditRecorder())
            ->handle(new ManageGeoip($action, 'Oper', 44, new DateTimeImmutable(), $countryCode, 'reason'));

        self::assertSame($expected, $result->outcome);
        self::assertSame([], $repository->saved);
    }

    private function handler(RecordingGeoipCountryRepository $repository, RecordingGeoipNetworkActions $network, RecordingGeoipAuditRecorder $audit): ManageGeoipHandler
    {
        return new ManageGeoipHandler($repository, $network, $audit);
    }
}

final class RecordingGeoipCountryRepository implements GeoipCountryRepository
{
    /** @var list<GeoipCountryEntry> */
    public array $entries;

    /** @var list<array{string, string, string, DateTimeImmutable}> */
    public array $saved = [];

    /** @var list<GeoipCountryEntry> */
    public array $removed = [];

    /** @param list<GeoipCountryEntry> $entries */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public function findByCode(string $countryCode): ?GeoipCountryEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->countryCode === strtoupper($countryCode)) {
                return $entry;
            }
        }

        return null;
    }

    public function findAll(): array
    {
        return $this->entries;
    }

    public function save(string $countryCode, string $reason, string $registeredByNick, DateTimeImmutable $registeredAt): void
    {
        $this->saved[] = [$countryCode, $reason, $registeredByNick, $registeredAt];
    }

    public function remove(GeoipCountryEntry $entry): void
    {
        $this->removed[] = $entry;
    }
}

final class RecordingGeoipNetworkActions implements GlineNetworkActions
{
    /** @var list<array{string, ?DateTimeImmutable, string}> */
    public array $added = [];

    /** @var list<string> */
    public array $removed = [];

    /** @var list<array{string, string}> */
    public array $addedByMask = [];

    /** @var list<string> */
    public array $removedByMask = [];

    public function add(string $mask, ?DateTimeImmutable $expiresAt, string $reason): void
    {
        $this->added[] = [$mask, $expiresAt, $reason];
    }

    public function remove(string $mask): void
    {
        $this->removed[] = $mask;
    }

    public function addByMask(string $mask, string $reason): void
    {
        $this->addedByMask[] = [$mask, $reason];
    }

    public function removeByMask(string $mask): void
    {
        $this->removedByMask[] = $mask;
    }
}

final class RecordingGeoipAuditRecorder implements CommandAuditRecorder
{
    /** @var list<CommandAuditRecord> */
    public array $records = [];

    public function record(CommandAuditRecord $record): void
    {
        $this->records[] = $record;
    }
}
