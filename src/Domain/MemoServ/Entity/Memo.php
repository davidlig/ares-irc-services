<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A memo sent to a nickname or a channel.
 * Exactly one of targetNickId or targetChannelId must be set.
 */
class Memo
{
    private int $id;

    private ?int $targetNickId = null;

    private ?int $targetChannelId = null;

    private int $senderNickId;

    private string $message;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $readAt = null;

    public function __construct(
        ?int $targetNickId,
        ?int $targetChannelId,
        int $senderNickId,
        string $message,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $nickSet = null !== $targetNickId;
        $channelSet = null !== $targetChannelId;
        if ($nickSet === $channelSet) {
            throw new InvalidArgumentException('Exactly one of targetNickId or targetChannelId must be set.');
        }
        $this->targetNickId = $targetNickId;
        $this->targetChannelId = $targetChannelId;
        $this->senderNickId = $senderNickId;
        $this->message = $message;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTargetNickId(): ?int
    {
        return $this->targetNickId;
    }

    public function getTargetChannelId(): ?int
    {
        return $this->targetChannelId;
    }

    public function getSenderNickId(): int
    {
        return $this->senderNickId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function markAsRead(?DateTimeImmutable $at = null): void
    {
        $this->readAt = $at ?? new DateTimeImmutable();
    }

    /**
     * Used by Doctrine hydration; do not call directly.
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }
}
