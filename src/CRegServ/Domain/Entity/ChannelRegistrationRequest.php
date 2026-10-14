<?php

declare(strict_types=1);

namespace App\CRegServ\Domain\Entity;

use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use DateTimeImmutable;
use LogicException;

/**
 * A channel registration request awaiting manual approval by a services oper.
 *
 * It carries everything APROBAR needs to hand the registration over to
 * RegisterChannel, so a pending request never writes to UDB: the C block is
 * only produced once the request is approved.
 *
 * Persistence mapping is defined in config/doctrine (XML); Domain has no Doctrine dependency.
 */
class ChannelRegistrationRequest
{
    private int $id;

    private string $channel;

    private string $channelLower;

    private int $requesterAccountId;

    private string $requesterNick;

    private string $description;

    private ChannelRegistrationStatus $status = ChannelRegistrationStatus::Pending;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $approvedAt = null;

    private ?string $approvedByNick = null;

    private ?DateTimeImmutable $deniedAt = null;

    private ?string $deniedByNick = null;

    private ?string $deniedReason = null;

    private function __construct(DateTimeImmutable $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    public static function request(
        DateTimeImmutable $requestedAt,
        string $channelName,
        int $requesterAccountId,
        string $requesterNick,
        string $description = '',
    ): self {
        $request = new self($requestedAt);
        $request->channel = $channelName;
        $request->channelLower = strtolower($channelName);
        $request->requesterAccountId = $requesterAccountId;
        $request->requesterNick = $requesterNick;
        $request->description = $description;

        return $request;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function getChannelLower(): string
    {
        return $this->channelLower;
    }

    public function getRequesterAccountId(): int
    {
        return $this->requesterAccountId;
    }

    public function getRequesterNick(): string
    {
        return $this->requesterNick;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatus(): ChannelRegistrationStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getApprovedByNick(): ?string
    {
        return $this->approvedByNick;
    }

    public function getDeniedAt(): ?DateTimeImmutable
    {
        return $this->deniedAt;
    }

    public function getDeniedByNick(): ?string
    {
        return $this->deniedByNick;
    }

    public function getDeniedReason(): ?string
    {
        return $this->deniedReason;
    }

    public function isPending(): bool
    {
        return ChannelRegistrationStatus::Pending === $this->status;
    }

    public function isApproved(): bool
    {
        return ChannelRegistrationStatus::Approved === $this->status;
    }

    public function isDenied(): bool
    {
        return ChannelRegistrationStatus::Denied === $this->status;
    }

    public function approve(string $operNick, DateTimeImmutable $approvedAt): void
    {
        if (!$this->isPending()) {
            throw new LogicException('Only pending requests can be approved.');
        }

        $this->status = ChannelRegistrationStatus::Approved;
        $this->approvedAt = $approvedAt;
        $this->approvedByNick = $operNick;
    }

    public function deny(string $operNick, ?string $reason, DateTimeImmutable $deniedAt): void
    {
        if (!$this->isPending()) {
            throw new LogicException('Only pending requests can be denied.');
        }

        $this->status = ChannelRegistrationStatus::Denied;
        $this->deniedAt = $deniedAt;
        $this->deniedByNick = $operNick;
        $this->deniedReason = $reason;
    }
}
