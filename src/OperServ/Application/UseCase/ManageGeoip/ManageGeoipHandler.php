<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGeoip;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\GeoipCountryRepository;
use App\OperServ\Application\Port\Out\GlineNetworkActions;
use App\OperServ\Domain\Entity\GeoipCountry;

use function trim;

final readonly class ManageGeoipHandler implements ManageGeoipHandlerInterface
{
    public function __construct(
        private GeoipCountryRepository $countries,
        private GlineNetworkActions $network,
        private CommandAuditRecorder $audit,
    ) {}

    public function handle(ManageGeoip $command): ManageGeoipResult
    {
        return match ($command->action) {
            GeoipAction::Add => $this->add($command),
            GeoipAction::Delete => $this->delete($command),
            GeoipAction::List => $this->list($command),
            GeoipAction::Unknown => new ManageGeoipResult(ManageGeoipOutcome::UnknownAction),
        };
    }

    private function add(ManageGeoip $command): ManageGeoipResult
    {
        $countryCode = GeoipCountry::normalizeCode($command->countryCode ?? '');
        if ('' === $countryCode || !GeoipCountry::isKnownCode($countryCode)) {
            return new ManageGeoipResult(ManageGeoipOutcome::UnknownCountryCode, countryCode: $command->countryCode);
        }

        $reason = trim($command->reason ?? '');
        if ('' === $reason) {
            return new ManageGeoipResult(ManageGeoipOutcome::InvalidRequest, countryCode: $countryCode);
        }

        if (null !== $this->countries->findByCode($countryCode)) {
            return new ManageGeoipResult(ManageGeoipOutcome::AlreadyExists, countryCode: $countryCode);
        }

        $this->countries->save($countryCode, $reason, $command->actor, $command->occurredAt);
        $this->network->addByMask(GeoipCountry::maskFor($countryCode), $reason);
        $this->recordAudit($command, 'GEOIP ADD', $countryCode, $reason);

        return new ManageGeoipResult(ManageGeoipOutcome::Added, countryCode: $countryCode, reason: $reason);
    }

    private function delete(ManageGeoip $command): ManageGeoipResult
    {
        $countryCode = GeoipCountry::normalizeCode($command->countryCode ?? '');
        if ('' === $countryCode || !GeoipCountry::isKnownCode($countryCode)) {
            return new ManageGeoipResult(ManageGeoipOutcome::UnknownCountryCode, countryCode: $command->countryCode);
        }

        $country = $this->countries->findByCode($countryCode);
        if (null === $country) {
            return new ManageGeoipResult(ManageGeoipOutcome::NotFound, countryCode: $countryCode);
        }

        $this->countries->remove($country);
        $this->network->removeByMask(GeoipCountry::maskFor($countryCode));
        $this->recordAudit($command, 'GEOIP DEL', $countryCode, null);

        return new ManageGeoipResult(ManageGeoipOutcome::Deleted, countryCode: $countryCode);
    }

    private function list(ManageGeoip $command): ManageGeoipResult
    {
        $countries = $this->countries->findAll();
        if ([] === $countries) {
            return new ManageGeoipResult(ManageGeoipOutcome::ListEmpty);
        }

        $entries = [];
        foreach ($countries as $country) {
            $entries[] = new GeoipCountryListEntry(
                $country->countryCode,
                $country->reason,
                $country->registeredByNick,
                $country->registeredAt,
            );
        }

        return new ManageGeoipResult(ManageGeoipOutcome::Listed, entries: $entries);
    }

    private function recordAudit(ManageGeoip $command, string $operation, string $target, ?string $reason): void
    {
        $this->audit->record(new CommandAuditRecord(
            CommandAuditCategory::OperatorAction,
            'operserv',
            $command->actor,
            $operation,
            $command->occurredAt,
            $target,
            $reason,
            'operserv.geoip',
        ));
    }
}
