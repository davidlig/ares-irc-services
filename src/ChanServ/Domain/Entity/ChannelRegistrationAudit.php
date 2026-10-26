<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Entity;

use DateTimeImmutable;

final class ChannelRegistrationAudit
{
    private int $id;

    private function __construct(
        private string $channelName,
        private int $requesterAccountId,
        private int $operatorAccountId,
        private string $operatorNickname,
        private bool $accepted,
        private ?string $rejectionReason,
        private ?DateTimeImmutable $restrictedUntil,
        private DateTimeImmutable $occurredAt,
    ) {}

    public static function recordDecision(
        string $channelName,
        int $requesterAccountId,
        int $operatorAccountId,
        string $operatorNickname,
        bool $accepted,
        ?string $rejectionReason,
        ?DateTimeImmutable $restrictedUntil,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self($channelName, $requesterAccountId, $operatorAccountId, $operatorNickname, $accepted, $rejectionReason, $restrictedUntil, $occurredAt);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getRequesterAccountId(): int
    {
        return $this->requesterAccountId;
    }

    public function getOperatorAccountId(): int
    {
        return $this->operatorAccountId;
    }

    public function getOperatorNickname(): string
    {
        return $this->operatorNickname;
    }

    public function wasAccepted(): bool
    {
        return $this->accepted;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function getRestrictedUntil(): ?DateTimeImmutable
    {
        return $this->restrictedUntil;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
