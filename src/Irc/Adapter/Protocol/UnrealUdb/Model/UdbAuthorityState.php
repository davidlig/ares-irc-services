<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use DateTimeImmutable;
use OverflowException;
use UnexpectedValueException;

/**
 * Explicit approval for the complete local UDB dataset.
 *
 * Block checksums describe individual blocks only; they do not prove that the
 * six-block store was imported as one validated generation.
 */
class UdbAuthorityState
{
    public const string CURRENT_PROTOCOL_REVISION = 'eff97e9f8e90a58f25504744d32d2fa16d3536bb';

    private int $id;

    private ?DateTimeImmutable $approvedAt = null;

    private ?string $fingerprint = null;

    private ?string $protocolRevision = null;

    private ?string $originSid = null;

    private ?string $originEpoch = null;

    private ?string $originWatermark = null;

    private ?string $datasetSid = null;

    private ?string $datasetEpoch = null;

    private ?string $datasetWatermark = null;

    public function __construct(
        private bool $approved = false,
    ) {}

    public function isApproved(): bool
    {
        return $this->approved && self::CURRENT_PROTOCOL_REVISION === $this->protocolRevision;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getFingerprint(): ?string
    {
        return $this->fingerprint;
    }

    public function getProtocolRevision(): ?string
    {
        return $this->protocolRevision;
    }

    public function getImportedCheckpoint(): ?UdbDatasetCheckpoint
    {
        if (null === $this->originSid || null === $this->originEpoch || null === $this->originWatermark) {
            return null;
        }

        $watermark = UdbUnsignedDecimal::parse($this->originWatermark);
        if (null === $watermark) {
            return null;
        }

        return new UdbDatasetCheckpoint(new UdbStreamOrigin($this->originSid, $this->originEpoch), $watermark);
    }

    public function recordImportedCheckpoint(UdbDatasetCheckpoint $checkpoint): void
    {
        $this->originSid = $checkpoint->origin->sid;
        $this->originEpoch = $checkpoint->origin->epoch;
        $this->originWatermark = (string) $checkpoint->watermark;
    }

    public function getDatasetCheckpoint(): ?UdbDatasetCheckpoint
    {
        if (null === $this->datasetSid || null === $this->datasetEpoch || null === $this->datasetWatermark) {
            return null;
        }
        $watermark = UdbUnsignedDecimal::parse($this->datasetWatermark);
        if (null === $watermark) {
            return null;
        }

        return new UdbDatasetCheckpoint(new UdbStreamOrigin($this->datasetSid, $this->datasetEpoch), $watermark);
    }

    public function startDatasetStream(UdbStreamOrigin $origin): UdbDatasetCheckpoint
    {
        $checkpoint = $this->getDatasetCheckpoint();
        if (null !== $checkpoint && $checkpoint->origin->sid === $origin->sid && $checkpoint->origin->epoch === $origin->epoch) {
            return $checkpoint;
        }
        $this->datasetSid = $origin->sid;
        $this->datasetEpoch = $origin->epoch;
        $this->datasetWatermark = '0';

        return new UdbDatasetCheckpoint($origin, UdbUnsignedDecimal::fromInt(0));
    }

    public function recordDatasetCheckpoint(UdbDatasetCheckpoint $checkpoint): void
    {
        $this->datasetSid = $checkpoint->origin->sid;
        $this->datasetEpoch = $checkpoint->origin->epoch;
        $this->datasetWatermark = (string) $checkpoint->watermark;
    }

    public function advanceDatasetCheckpoint(UdbStreamOrigin $origin): UdbDatasetCheckpoint
    {
        $checkpoint = $this->getDatasetCheckpoint();
        if (null === $checkpoint || $checkpoint->origin->sid !== $origin->sid || $checkpoint->origin->epoch !== $origin->epoch) {
            throw new UnexpectedValueException('The active UDB dataset origin changed during a mutation.');
        }
        $watermark = $checkpoint->watermark->increment();
        if (null === $watermark) {
            throw new OverflowException('The UDB dataset sequence is exhausted for this epoch.');
        }
        $this->datasetWatermark = (string) $watermark;

        return new UdbDatasetCheckpoint($origin, $watermark);
    }

    public function clearImportedCheckpoint(): void
    {
        $this->originSid = null;
        $this->originEpoch = null;
        $this->originWatermark = null;
    }

    public function approve(string $fingerprint): void
    {
        $this->approved = true;
        $this->fingerprint = $fingerprint;
        $this->protocolRevision = self::CURRENT_PROTOCOL_REVISION;
        $this->approvedAt = new DateTimeImmutable();
    }

    public function revoke(): void
    {
        $this->approved = false;
        $this->fingerprint = null;
        $this->protocolRevision = null;
        $this->approvedAt = null;
    }
}
