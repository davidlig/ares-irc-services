<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use DateTimeImmutable;

/**
 * Explicit approval for the complete local UDB dataset.
 *
 * Block checksums describe individual blocks only; they do not prove that the
 * six-block store was imported as one validated generation.
 */
class UdbAuthorityState
{
    public const string CURRENT_PROTOCOL_REVISION = 'ac3b91566368eabb987129c7dcf7660558573e15';

    private int $id;

    private ?DateTimeImmutable $approvedAt = null;

    private ?string $fingerprint = null;

    private ?string $protocolRevision = null;

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
